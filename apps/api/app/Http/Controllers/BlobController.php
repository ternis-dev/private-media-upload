<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\HttpRange;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\R2S3Driver;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BlobController extends Controller
{
    public function __construct(private UploadService $svc, private Store $store)
    {
    }

    public function show(Request $request, string $key): SymfonyResponse
    {
        $key = implode('/', array_map('rawurldecode', explode('/', $key)));
        if (!preg_match('#^u/(l1|l2|l3)/#', $key, $tm) || str_contains($key, '..')) {
            throw new ApiError(400, ['error' => 'invalid key']);
        }
        $tier = strtoupper($tm[1]);
        $driver = Drivers::forTier($tier);
        if ($tier === 'L1' && $driver instanceof R2S3Driver && $driver->isReal()) {
            throw new ApiError(404, ['error' => 'L1 bytes are served by object storage; use the short link']);
        }
        $expires = (int) $request->query('expires', 0);
        $sig = (string) $request->query('sig', '');
        if (!$driver->verifySignedUrl($key, $expires, $sig)) {
            throw new ApiError(403, ['error' => 'bad or expired signature']);
        }
        $shareRow = $this->store->getShareByKey($key);
        $row = Api::need($this->store, $shareRow, $shareRow['id'] ?? null, $request);
        $shareId = $row['id'];
        // Thumbnail reads are previews: same gates, correct JPEG type, no view consumed.
        $isThumb = $shareRow['thumb_key'] !== null && $shareRow['thumb_key'] === $key;
        $consume = $isThumb
            ? ['ok' => true, 'spent' => false]
            : $this->store->tryConsumeView($shareId);
        if (!$consume['ok']) {
            Api::audit($this->store, $shareId, $consume['reason'], $request);
            throw new ApiError($consume['reason'] === 'not-found' ? 404 : 410, ['error'
                => $consume['reason'] === 'exhausted' ? 'view limit reached' : 'share ' . $consume['reason']]);
        }
        try {
            $size = $driver->size($key);
        } catch (\RuntimeException) {
            Api::audit($this->store, $shareId, 'blob-gone', $request);
            throw new ApiError(410, ['error' => 'blob gone (expired/purged?)']);
        }
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $rangeHeader = $request->headers->get('Range', '');
        if ($rangeHeader !== '') {
            $range = HttpRange::parse($rangeHeader, $size);
            if ($range === null) {
                Api::audit($this->store, $shareId, 'bad-range', $request);
                return response('', 416, ["Content-Range" => "bytes */{$size}"]);
            }
            [$start, $end] = [$range['start'], $range['end']];
            $status = 206;
        }
        $headers = [
            'Content-Type' => $isThumb ? 'image/jpeg' : ($shareRow['mime'] ?? 'application/octet-stream'),
            'Content-Disposition' => 'attachment',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        $svc = $this->svc;
        $store = $this->store;
        $spent = $consume['spent'];
        $response = new StreamedResponse(function () use ($driver, $key, $start, $end, $svc, $store, $shareId, $spent) {
            for ($off = $start; $off <= $end; $off += 1048576) {
                echo $driver->readRange($key, $off, (int) min(1048576, $end - $off + 1));
                flush();
            }
            if ($spent) {
                $svc->deleteShareBlob($shareId, static fn (string $t) => Drivers::forTier($t));
            }
        }, $status, $headers);
        Api::audit($this->store, $shareId, $isThumb ? 'thumb-ok' : 'blob-ok', $request);
        return $response;
    }
}
