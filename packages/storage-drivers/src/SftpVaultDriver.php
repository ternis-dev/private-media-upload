<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

/**
 * L2 — Provider Vault DE (SFTP/Storage Box, single location).
 * M0: local emulation so contract tests run without an SFTP server.
 * M2: swap internals to league/flysystem-sftp-v3 against Hetzner box.
 */
final class SftpVaultDriver implements StorageDriverInterface
{
    private LocalSovereignDriver $inner;

    public function __construct(string $emulationRoot)
    {
        $this->inner = new LocalSovereignDriver($emulationRoot . '/vault-de');
    }

    public function tier(): Tier
    {
        return Tier::L2;
    }

    public function put(string $key, string $contents, array $meta = []): void
    {
        $this->inner->put($key, $contents, $meta);
    }

    public function get(string $key): string
    {
        return $this->inner->get($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function signedGetUrl(string $key, int $ttlSeconds = 900): string
    {
        // L2/L3 stream via PHP (no CDN); URL shape identical to L3 for now.
        return $this->inner->signedGetUrl($key, $ttlSeconds);
    }
}
