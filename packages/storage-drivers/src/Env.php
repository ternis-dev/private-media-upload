<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

/**
 * 12-factor env reader: $_SERVER → $_ENV → getenv.
 * Works identically under php -S, php-fpm, Laravel and Artisan,
 * regardless of variables_order / Dotenv putenv behavior.
 */
class Env
{
    public static function get(string $key, string $default = ''): string
    {
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        $v = getenv($key);
        return $v === false || $v === '' ? $default : $v;
    }
}
