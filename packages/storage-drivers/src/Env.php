<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

/**
 * 12-factor env reader: real environment (getenv) first, then $_SERVER/$_ENV.
 * getenv-first matters: Dotenv never overwrites real vars, and putenv() test
 * overrides stay visible. Falls back to superglobals for SAPI-only values
 * (e.g. fastcgi_param) that never reach the process environment.
 */
class Env
{
    public static function get(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        foreach ([$_SERVER[$key] ?? null, $_ENV[$key] ?? null] as $s) {
            if (is_string($s) && $s !== '') {
                return $s;
            }
        }
        return $default;
    }
}
