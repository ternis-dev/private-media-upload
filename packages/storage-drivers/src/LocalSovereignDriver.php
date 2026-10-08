<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

/**
 * L3 — Sovereign Node. Local disk, ONE location, highest privacy.
 * M0: plain filesystem under $root. M1+: LUKS note + atomic writes + fsync.
 */
final class LocalSovereignDriver implements StorageDriverInterface
{
    public function __construct(
        private readonly string $root,
        private readonly string $baseUrl = 'http://localhost:8000',
        private readonly string $hmacSecret = 'changeme-sovereign',
    ) {
        if (!is_dir($this->root)) {
            mkdir($this->root, 0700, true);
        }
    }

    public function tier(): Tier
    {
        return Tier::L3;
    }

    public function put(string $key, string $contents, array $meta = []): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        // Atomic write: tmp + rename.
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($tmp, $contents);
        rename($tmp, $path);
    }

    public function get(string $key): string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            throw new \RuntimeException("not found: {$key}");
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException("unreadable: {$key}");
        }
        return $data;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function signedGetUrl(string $key, int $ttlSeconds = 900): string
    {
        $expires = time() + $ttlSeconds;
        $sig = hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret);
        return rtrim($this->baseUrl, '/') . '/v1/blobs/' . rawurlencode($key)
            . '?expires=' . $expires . '&sig=' . $sig;
    }

    public function verifySignedUrl(string $key, int $expires, string $sig): bool
    {
        if ($expires < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret);
        return hash_equals($expected, $sig);
    }

    private function path(string $key): string
    {
        if (str_contains($key, '..')) {
            throw new \InvalidArgumentException('invalid key');
        }
        return rtrim($this->root, '/') . '/' . ltrim($key, '/');
    }
}
