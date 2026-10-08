<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

use Aws\S3\S3Client;

/**
 * L1 — Edge Object Storage (Cloudflare R2 default, S3-compatible).
 *
 * Two modes:
 * - Real (M1): S3_L1_* env present → AWS SigV4 presigned PUT/GET via endpoint
 *   override (works for R2, Hetzner Object Storage, MinIO, AWS).
 * - Emulation (dev/test fallback): local dir mimicking bucket layout so
 *   contract tests run with zero infrastructure.
 */
final class R2S3Driver implements StorageDriverInterface
{
    private ?S3Client $client = null;

    public function __construct(
        private readonly string $emulationRoot = '',
        private readonly string $bucket = 'privatewf-l1',
        private readonly string $publicBase = 'https://r2.private.wf',
        private readonly string $endpoint = '',
        private readonly string $key = '',
        private readonly string $secret = '',
        private readonly string $region = 'auto',
        private readonly string $emuBaseUrl = 'http://localhost:8000',
        private readonly string $emuSecret = 'changeme-sovereign',
    ) {
        if ($this->endpoint !== '' && $this->key !== '') {
            $this->client = new S3Client([
                'version' => 'latest',
                'region' => $this->region,
                'endpoint' => $this->endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => $this->key, 'secret' => $this->secret],
            ]);
        }
    }

    public static function fromEnv(): self
    {
        return new self(
            emulationRoot: Env::get('S3_L1_EMU_ROOT') ?: sys_get_temp_dir() . '/pwf-l1-emu',
            bucket: Env::get('S3_L1_BUCKET') ?: 'privatewf-l1',
            publicBase: Env::get('S3_L1_PUBLIC_BASE') ?: 'https://r2.private.wf',
            endpoint: Env::get('S3_L1_ENDPOINT') ?: '',
            key: Env::get('S3_L1_KEY') ?: '',
            secret: Env::get('S3_L1_SECRET') ?: '',
            region: Env::get('S3_L1_REGION') ?: 'auto',
            emuBaseUrl: Env::get('APP_URL') ?: 'http://localhost:8000',
            emuSecret: Env::get('HMAC_SECRET') ?: 'changeme-sovereign',
        );
    }

    public function isReal(): bool
    {
        return $this->client !== null;
    }

    public function tier(): Tier
    {
        return Tier::L1;
    }

    public function ensureBucket(): void
    {
        if ($this->client === null) {
            return;
        }
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable) {
            $this->client->createBucket(['Bucket' => $this->bucket]);
        }
    }

    /** Real SigV4 presigned PUT (browser → R2 direct). Emulation: local pseudo-URL. */
    public function presignedPutUrl(string $key, string $mime, int $ttlSeconds = 3600): string
    {
        if ($this->client === null) {
            return rtrim($this->publicBase, '/') . '/emu-upload/' . ltrim($key, '/')
                . '?mime=' . rawurlencode($mime) . '&expires_in=' . $ttlSeconds;
        }
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $mime,
        ]);
        return (string) $this->client->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds')->getUri();
    }

    public function put(string $key, string $contents, array $meta = []): void
    {
        if ($this->client === null) {
            $this->emu()->put($key, $contents, $meta);
            return;
        }
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $contents]);
    }

    public function putFile(string $key, string $path, array $meta = []): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("no such file: {$path}");
        }
        if ($this->client === null) {
            $this->emu()->putFile($key, $path, $meta);
            return;
        }
        $handle = fopen($path, 'rb');
        try {
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $handle]);
        } finally {
            fclose($handle);
        }
    }

    public function get(string $key): string
    {
        if ($this->client === null) {
            return $this->emu()->get($key);
        }
        try {
            $res = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return (string) $res['Body'];
        } catch (\Throwable $e) {
            throw new \RuntimeException("not found: {$key}", 0, $e);
        }
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1) {
            throw new \InvalidArgumentException('invalid range');
        }
        if ($this->client === null) {
            return $this->emu()->readRange($key, $offset, $length);
        }
        try {
            $res = $this->client->getObject([
                'Bucket' => $this->bucket, 'Key' => $key,
                'Range' => "bytes={$offset}-" . ($offset + $length - 1),
            ]);
            return (string) $res['Body'];
        } catch (\Throwable $e) {
            throw new \RuntimeException("not found: {$key}", 0, $e);
        }
    }

    public function exists(string $key): bool
    {
        if ($this->client === null) {
            return $this->emu()->exists($key);
        }
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    public function size(string $key): int
    {
        if ($this->client === null) {
            return $this->emu()->size($key);
        }
        try {
            $res = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return (int) $res['ContentLength'];
        } catch (\Throwable $e) {
            throw new \RuntimeException("not found: {$key}", 0, $e);
        }
    }

    public function delete(string $key): void
    {
        if ($this->client === null) {
            $this->emu()->delete($key);
            return;
        }
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    public function signedGetUrl(string $key, int $ttlSeconds = 900): string
    {
        if ($this->client === null) {
            // Emulation: routable HMAC URL served by apps/api (/v1/blobs).
            return (new LocalSovereignDriver(
                $this->emulationRoot . '/' . $this->bucket, $this->emuBaseUrl, $this->emuSecret
            ))->signedGetUrl($key, $ttlSeconds);
        }
        $cmd = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        return (string) $this->client->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds')->getUri();
    }

    /** Emulation: verify an HMAC blob URL. Real mode: SigV4 verifies itself → false. */
    public function verifySignedUrl(string $key, int $expires, string $sig): bool
    {
        if ($this->client !== null) {
            return false;
        }
        return $this->emu()->verifySignedUrl($key, $expires, $sig);
    }

    /** Emulation-only: local path for zero-copy streaming. */
    public function localPath(string $key): string
    {
        $this->requireEmu();
        return $this->emu()->localPath($key);
    }

    private function requireEmu(): void
    {
        if ($this->client !== null) {
            throw new \LogicException('emulation-only method in real S3 mode');
        }
    }

    private function emu(): LocalSovereignDriver
    {
        return new LocalSovereignDriver(
            rtrim($this->emulationRoot, '/') . '/' . $this->bucket, $this->emuBaseUrl, $this->emuSecret);
    }
}
