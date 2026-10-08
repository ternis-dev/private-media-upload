<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

final class ShareController extends Controller
{
    public function __construct(private UploadService $svc, private Store $store)
    {
    }

    public function meta(Request $request, string $id): JsonResponse
    {
        $row = Api::need($this->store, $this->store->getShare($id), $id, $request);
        Api::audit($this->store, $id, 'meta-ok', $request);
        return response()->json($this->svc->meta($id));
    }

    public function export(Request $request, string $id): JsonResponse
    {
        $row = Api::need($this->store, $this->store->getShare($id), $id, $request);
        Api::audit($this->store, $id, 'export-ok', $request);
        return response()->json($this->svc->export($id, Api::sharePassword($request)));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = $this->store->getShare($id);
        if ($row === null) {
            throw new ApiError(404, ['error' => 'unknown share']);
        }
        // Destructive: password required when set (unless already dead).
        $st = UploadService::authorize($row, Api::sharePassword($request));
        if ($st === 'password-required') {
            throw new ApiError(401, ['error' => 'password required', 'passwordRequired' => true]);
        }
        if ($st === 'password-wrong') {
            Api::audit($this->store, $id, 'bad-password', $request);
            throw new ApiError(401, ['error' => 'wrong password']);
        }
        $this->store->revokeShare($id, time());
        try {
            Drivers::forTier($row['tier'])->delete($row['storage_key']);
        } catch (\Throwable) {
        }
        Api::audit($this->store, $id, 'revoked', $request);
        return response()->json(['revoked' => true]);
    }
}
