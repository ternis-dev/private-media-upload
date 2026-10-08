<?php

declare(strict_types=1);

namespace PrivateWf\Api;

// Note: unqualified Env resolves to PrivateWf\Api\Env (storage-backed).

use PrivateWf\Storage\Env;

/**
 * Post-upload thumbnails (M4). Images via GD, video via ffmpeg first-frame.
 * Never runs on E2EE ciphertext (nothing to see) or oversize sources.
 */
final class Thumbnailer
{
    public const MAX_SOURCE_BYTES = 50 * 1024 * 1024;
    public const MAX_EDGE_PX = 512;

    public static function enabled(): bool
    {
        return Env::get('THUMBS', '1') === '1';
    }

    /** @return null|string JPEG bytes when a thumbnail could be made */
    public static function make(string $srcPath, string $mime, int $size): ?string
    {
        if (!self::enabled() || $size > self::MAX_SOURCE_BYTES) {
            return null;
        }
        if (str_starts_with($mime, 'image/')) {
            return self::fromImage($srcPath);
        }
        if (str_starts_with($mime, 'video/')) {
            return self::fromVideo($srcPath);
        }
        return null;
    }

    private static function fromImage(string $srcPath): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $data = file_get_contents($srcPath);
        if ($data === false) {
            return null;
        }
        $img = imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }
        try {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1.0, self::MAX_EDGE_PX / max(1, max($w, $h)));
            if ($scale < 1.0) {
                $resized = imagescale($img, (int) ($w * $scale), (int) ($h * $scale));
                if ($resized !== false) {
                    $img = $resized; // GC frees the original (imagedestroy deprecated 8.5)
                }
            }
            ob_start();
            $ok = imagejpeg($img, null, 78);
            $out = ob_get_clean();
            return $ok && $out !== false ? $out : null;
        } finally {
            unset($img);
        }
    }

    private static function fromVideo(string $srcPath): ?string
    {
        $ffmpeg = Env::get('FFMPEG_BIN', 'ffmpeg');
        if (!self::executable($ffmpeg)) {
            return null;
        }
        $tmp = sys_get_temp_dir() . '/pwf-thumb-' . bin2hex(random_bytes(8)) . '.jpg';
        $cmd = escapeshellarg($ffmpeg) . ' -hide_banner -loglevel error -y -ss 1 -i '
            . escapeshellarg($srcPath) . ' -vframes 1 -vf scale=512:-1 -q:v 4 '
            . escapeshellarg($tmp) . ' 2>/dev/null';
        exec($cmd, $ignored, $code);
        if ($code !== 0 || !is_file($tmp)) {
            return null;
        }
        $data = file_get_contents($tmp);
        unlink($tmp);
        return $data === false ? null : $data;
    }

    private static function executable(string $bin): bool
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin);
        }
        $path = shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
        return $path !== null && trim($path) !== '';
    }
}
