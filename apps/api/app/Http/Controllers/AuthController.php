<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

final class AuthController extends Controller
{
    public function __construct(private UploadService $svc, private Store $store)
    {
    }

    public function register(Request $request): JsonResponse
    {
        try {
            return response()->json($this->svc->register(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                Drivers::defaultQuota()), 201);
        } catch (\InvalidArgumentException $e) {
            throw new ApiError(422, ['error' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new ApiError(409, ['error' => $e->getMessage()]);
        }
    }

    public function login(Request $request): JsonResponse
    {
        try {
            return response()->json($this->svc->login(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) $request->input('name', 'api')));
        } catch (\RuntimeException) {
            throw new ApiError(401, ['error' => 'invalid credentials']);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $h = $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/', $h, $m)) {
            $this->store->revokeToken(hash('sha256', $m[1]));
        }
        return response()->json(['ok' => true]);
    }
}
