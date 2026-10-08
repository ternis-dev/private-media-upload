<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

/**
 * L1 — Edge Object Storage (Cloudflare R2 default, S3-compatible).
 * M0: local emulation so contract tests run without MinIO/R2.
 * M1: swap internals to league/flysystem-aws-s3-v3 with endpoint override
 *     + presigned multipart URLs. Public surface stays identical.
 */
final class R2S3Driver implements StorageDriverInterface
{
    private LocalSovereignDriver $inner;

    public function __construct(
        string $emulationRoot,
        private readonly string $bucket = 'privatewf-l1',
        private readonly string $publicBase = 'https://r2.private.wf',
    ) {
        $this->inner = new LocalSovereignDriver($emulationRoot . '/' . $bucket);
    }

    public function tier(): Tier
    {
        return Tier::L1;
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
        $expires = time() + $ttlSeconds;
        // M0 placeholder format; M1 becomes real SigV4 presign.
        return rtrim($this->publicBase, '/') . '/' . $this->bucket . '/' . ltrim($key, '/')
            . '?expires=' . $expires . '&tier=L1';
    }
}
