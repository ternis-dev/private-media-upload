<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;
use PrivateWf\Api\Drivers;
use PrivateWf\Api\Store;
use PrivateWf\Api\UploadService;

/** Shared API plumbing; behavior mirrors the pre-Laravel router 1:1. */
final class Api
{
    /** Share password: header preferred (query leaks into logs). */
    public static function sharePassword(Request $request): ?string
    {
        $h = $request->header('X-Share-Password');
        if ($h !== null && $h !== '') {
            return $h;
        }
        $q = $request->query('password');
        return $q !== null && $q !== '' ? (string) $q : null;
    }

    public static function audit(Store $store, ?string $shareId, string $result, Request $request): void
    {
        if ($shareId === null) {
            return;
        }
        $salt = Drivers::auditSalt();
        $store->logAccess(
            $shareId,
            hash_hmac('sha256', (string) $request->ip(), $salt),
            hash_hmac('sha256', (string) $request->userAgent(), $salt),
            $result,
            time());
    }

    /**
     * Gate a share row. Returns the row on ok, else audits + throws ApiError.
     * Statuses: ok | not-found | revoked | expired | exhausted |
     *          password-required | password-wrong.
     */
    public static function need(Store $store, ?array $row, ?string $shareId, Request $request): array
    {
        if ($row === null) {
            throw new ApiError(404, ['error' => 'unknown share']);
        }
        $st = UploadService::authorize($row, self::sharePassword($request));
        if ($st === 'ok') {
            return $row;
        }
        self::audit($store, $shareId, match ($st) {
            'password-required', 'password-wrong' => 'bad-password',
            default => $st,
        }, $request);
        throw match ($st) {
            'revoked' => new ApiError(410, ['error' => 'share revoked']),
            'expired' => new ApiError(410, ['error' => 'share expired']),
            'exhausted' => new ApiError(410, ['error' => 'view limit reached']),
            'password-required' => new ApiError(401, ['error' => 'password required', 'passwordRequired' => true]),
            default => new ApiError(401, ['error' => 'wrong password']),
        };
    }
}
