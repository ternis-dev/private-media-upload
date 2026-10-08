<?php

declare(strict_types=1);

namespace PrivateWf\Api;

/** M0 share/upload helpers. Persistence (pgsql) + auth land in M1 (Laravel). */
final class Shares
{
    public const ID_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    public const MAX_FILE_BYTES = 5 * 1024 * 1024 * 1024; // 5 GB v1 cap

    public static function newId(int $len = 12): string
    {
        $alphabet = self::ID_ALPHABET;
        $n = strlen($alphabet);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, $n - 1)];
        }
        return $out;
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z]{8,32}$/', $id);
    }

    /** Validate upload-init payload. Returns [ok, error]. */
    public static function validateInit(array $p): array
    {
        $tier = $p['tier'] ?? '';
        if (!in_array($tier, ['L1', 'L2', 'L3'], true)) {
            return [false, 'tier must be L1|L2|L3'];
        }
        $name = (string) ($p['filename'] ?? '');
        if ($name === '' || strlen($name) > 255 || str_contains($name, '/') || str_contains($name, "\0")) {
            return [false, 'invalid filename'];
        }
        $size = (int) ($p['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            return [false, 'size must be 1..5GB'];
        }
        $mime = (string) ($p['mime'] ?? 'application/octet-stream');
        if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime)) {
            return [false, 'invalid mime'];
        }
        return [true, ''];
    }

    public static function defaultExpiry(string $tier): string
    {
        // L1/L2 7d, L3 30d — see .plans/01-privacy-tiers.md
        $days = $tier === 'L3' ? 30 : 7;
        return gmdate('c', time() + $days * 86400);
    }
}
