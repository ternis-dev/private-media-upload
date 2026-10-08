<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;
use PrivateWf\Storage\R2S3Driver;

final class UploadController extends Controller
{
    public function __construct(private UploadService $svc, private Store $store)
    {
    }

    public function init(Request $request): JsonResponse
    {
        $owner = $request->attributes->get('apiUser');
        try {
            $r = $this->svc->reserve(
                (string) $request->input('tier', ''),
                (string) $request->input('filename', ''),
                (int) $request->input('size', 0),
                (string) $request->input('mime', 'application/octet-stream'),
                $owner['id'] ?? null,
                $owner !== null ? (int) $owner['quota_bytes'] : null);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new ApiError(413, ['error' => $e->getMessage()]);
        }
        $res = [
            'uploadId' => $r['uploadId'], 'key' => $r['key'], 'tier' => $r['tier'],
            'expected' => $r['expected'],
            'appendUrl' => Drivers::appUrl() . '/v1/uploads/' . $r['uploadId'],
        ];
        if ($r['tier'] === 'L1') {
            $r2 = Drivers::forTier('L1');
            assert($r2 instanceof R2S3Driver);
            try {
                $r2->ensureBucket();
                $res['mode'] = $r2->isReal() ? 's3-direct' : 'proxy-via-append (dev emulation)';
                $res['presignedPutUrl'] = $r2->presignedPutUrl($r['key'],
                    (string) $request->input('mime', 'application/octet-stream'));
            } catch (\Throwable $e) {
                throw new ApiError(502, ['error' => 'L1 storage unreachable: ' . $e->getMessage()]);
            }
        } else {
            $res['mode'] = 'chunked-append';
        }
        return response()->json($res, 201);
    }

    public function append(Request $request, string $uploadId): JsonResponse
    {
        try {
            $r = $this->svc->append($uploadId, $request->getContent());
            return response()->json([
                'received' => $r['received'], 'expected' => $r['expected'],
                'done' => $r['received'] === $r['expected']]);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'exceeds') ? 413 : 404;
            throw new ApiError($code, ['error' => $e->getMessage()]);
        }
    }

    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $up = $this->store->getUpload($uploadId);
        if ($up === null) {
            throw new ApiError(404, ['error' => 'unknown upload']);
        }
        try {
            return response()->json($this->svc->complete($uploadId, Drivers::forTier($up['tier']), [
                'password' => $request->input('password'),
                'maxViews' => $request->input('maxViews'),
                'burn' => $request->input('burn', false),
                'e2ee' => $request->input('e2ee', false),
            ]), 201);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        }
    }

    public function l1Complete(Request $request): JsonResponse
    {
        $key = (string) $request->input('key', '');
        if (!str_starts_with($key, 'u/l1/') || str_contains($key, '..')) {
            throw new ApiError(422, ['error' => 'key must be a reserved u/l1/... key']);
        }
        $r2 = Drivers::forTier('L1');
        assert($r2 instanceof R2S3Driver);
        $owner = $request->attributes->get('apiUser');
        try {
            return response()->json($this->svc->completeL1(
                $key,
                (string) $request->input('filename', ''),
                (int) $request->input('size', 0),
                (string) $request->input('mime', 'application/octet-stream'),
                $r2,
                [
                    'password' => $request->input('password'),
                    'maxViews' => $request->input('maxViews'),
                    'burn' => $request->input('burn', false),
                    'e2ee' => $request->input('e2ee', false),
                ],
                $owner['id'] ?? null), 201);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        }
    }
}
