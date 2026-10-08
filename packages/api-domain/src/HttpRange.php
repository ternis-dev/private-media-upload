<?php

declare(strict_types=1);

namespace PrivateWf\Api;

/** Pure Range-request helper (unit-tested; router streams the chosen slice). */
final class HttpRange
{
    /**
     * @return null|array{start: int, end: int} null = invalid/unsatisfiable.
     * Supports single `bytes=start-end`, `bytes=start-`, `bytes=-suffix`.
     */
    public static function parse(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix <= 0) {
                return null;
            }
            $start = max(0, $size - $suffix);
            return ['start' => $start, 'end' => $size - 1];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : (int) $m[2];
        if ($start >= $size || $end < $start) {
            return null;
        }
        return ['start' => $start, 'end' => min($end, $size - 1)];
    }
}
