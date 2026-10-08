<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Shares;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use ZipArchive;

final class MeController extends Controller
{
    public function __construct(private UploadService $svc, private Store $store)
    {
    }

    public function assets(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiUser');
        $assets = $this->store->assetsFor($owner['id']);
        foreach ($assets as &$a) {
            $a['expiresAt'] = $a['expires_at'] !== null ? gmdate('c', (int) $a['expires_at']) : null;
            unset($a['expires_at']);
        }
        return response()->json([
            'assets' => $assets,
            'usage' => $this->store->userUsage($owner['id']),
            'quota' => (int) $owner['quota_bytes'],
        ]);
    }

    public function export(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $owner = $request->attributes->get('apiUser');
        if ($request->query('format') === 'zip') {
            $zipPath = sys_get_temp_dir() . '/pwf-export-' . bin2hex(random_bytes(8)) . '.zip';
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('manifest.json',
                json_encode($this->svc->exportUser($owner['id']), JSON_PRETTY_PRINT));
            foreach ($this->store->assetsFor($owner['id']) as $a) {
                $tmp = sys_get_temp_dir() . '/pwf-exp-' . bin2hex(random_bytes(8)) . '.bin';
                $fh = fopen($tmp, 'wb');
                $driver = Drivers::forTier($a['tier']);
                for ($off = 0; $off < $a['size']; $off += 1048576) {
                    fwrite($fh, $driver->readRange($a['storage_key'], $off, min(1048576, $a['size'] - $off)));
                }
                fclose($fh);
                $zip->addFile($tmp, 'files/' . $a['id'] . '-' . Shares::safeBasename($a['filename']));
                $tmps[] = $tmp;
            }
            $zip->close();
            foreach ($tmps ?? [] as $t) {
                unlink($t);
            }
            return response()->download($zipPath, 'privatewf-export.zip')->deleteFileAfterSend(true);
        }
        return response()->json($this->svc->exportUser($owner['id'])
            + ['usage' => $this->store->userUsage($owner['id'])]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiUser');
        $user = $this->store->findUserByEmail($owner['email']);
        if (!password_verify((string) $request->input('password', ''), $user['pw_hash'] ?? '')) {
            throw new ApiError(401, ['error' => 'invalid credentials']);
        }
        $n = $this->svc->deleteUserAccount($owner['id'], static fn (string $t) => Drivers::forTier($t));
        return response()->json(['deletedShares' => $n, 'account' => 'deleted']);
    }
}
