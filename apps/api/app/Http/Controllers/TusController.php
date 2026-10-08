<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Shares;
use PrivateWf\Api\UploadService;

final class TusController extends Controller
{
    public function __construct(private UploadService $svc)
    {
    }

    private function checkVersion(Request $request): void
    {
        if ($request->header('Tus-Resumable') !== '1.0.0') {
            throw new ApiError(412, ['error' => 'Tus-Resumable: 1.0.0 required']);
        }
    }

    public function options(): Response
    {
        return response('', 204, [
            'Tus-Version' => '1.0.0',
            'Tus-Extension' => 'creation,termination',
            'Tus-Max-Size' => (string) Shares::MAX_FILE_BYTES,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->checkVersion($request);
        $tier = in_array($request->query('tier', 'L2'), ['L1', 'L2', 'L3'], true)
            ? $request->query('tier') : 'L2';
        $owner = $request->attributes->get('apiUser');
        try {
            $r = $this->svc->tusCreate($tier, (int) $request->header('Upload-Length', 0),
                (string) $request->header('Upload-Metadata', ''),
                $owner['id'] ?? null,
                $owner !== null ? (int) $owner['quota_bytes'] : null);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new ApiError(413, ['error' => $e->getMessage()]);
        }
        return response('', 201, [
            'Tus-Resumable' => '1.0.0',
            'Location' => Drivers::appUrl() . '/v1/uploads/tus/' . $r['uploadId'],
        ]);
    }

    public function head(Request $request, string $uploadId): Response
    {
        $this->checkVersion($request);
        try {
            $cur = $this->svc->tusOffset($uploadId);
        } catch (\RuntimeException) {
            throw new ApiError(404, ['error' => 'unknown upload']);
        }
        return response('', 200, [
            'Tus-Resumable' => '1.0.0',
            'Upload-Offset' => (string) $cur['offset'],
            'Upload-Length' => (string) $cur['length'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function patch(Request $request, string $uploadId): Response
    {
        $this->checkVersion($request);
        if ($request->headers->get('Content-Type') !== 'application/offset+octet-stream') {
            throw new ApiError(415, ['error' => 'Content-Type must be application/offset+octet-stream']);
        }
        try {
            $r = $this->svc->tusAppend($uploadId, (int) $request->header('Upload-Offset', -1),
                $request->getContent());
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'mismatch') ? 409
                : (str_contains($e->getMessage(), 'exceeds') ? 413 : 404);
            throw new ApiError($code, ['error' => $e->getMessage()]);
        }
        return response('', 204, [
            'Tus-Resumable' => '1.0.0',
            'Upload-Offset' => (string) $r['received'],
        ]);
    }

    public function destroy(Request $request, string $uploadId): Response
    {
        $this->checkVersion($request);
        $this->svc->tusTerminate($uploadId);
        return response('', 204, ['Tus-Resumable' => '1.0.0']);
    }
}
