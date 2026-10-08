<?php

declare(strict_types=1);

namespace PrivateWf\Api;

use PrivateWf\Storage\LocalSovereignDriver;
use PrivateWf\Storage\R2S3Driver;
use PrivateWf\Storage\SftpVaultDriver;
use PrivateWf\Storage\StorageDriverInterface;

/** Env-wired driver factory. Same config must serve HTTP + purge CLI. */
final class Drivers
{
    public static function appUrl(): string
    {
        return getenv('APP_URL') ?: 'http://localhost:8000';
    }

    public static function webUrl(): string
    {
        return getenv('WEB_URL') ?: 'http://localhost:3000';
    }

    public static function hmacSecret(): string
    {
        return getenv('HMAC_SECRET') ?: 'changeme-sovereign';
    }

    public static function varDir(): string
    {
        return getenv('VAR_DIR') ?: __DIR__ . '/../var';
    }

    public static function dbPath(): string
    {
        return getenv('DB_PATH') ?: self::varDir() . '/privatewf.sqlite';
    }

    public static function forTier(string $tier): StorageDriverInterface
    {
        return match ($tier) {
            'L1' => R2S3Driver::fromEnv(),
            'L2' => new SftpVaultDriver(
                getenv('SFTP_L2_EMU_ROOT') ?: sys_get_temp_dir() . '/pwf-l2-emu',
                self::appUrl(), self::hmacSecret()),
            'L3' => new LocalSovereignDriver(
                getenv('SOVEREIGN_PATH') ?: sys_get_temp_dir() . '/pwf-sovereign',
                self::appUrl(), self::hmacSecret()),
            default => throw new \InvalidArgumentException('unknown tier'),
        };
    }
}
