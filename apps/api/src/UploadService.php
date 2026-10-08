<?php

declare(strict_types=1);

namespace PrivateWf\Api;

use PrivateWf\Storage\R2S3Driver;
use PrivateWf\Storage\StorageDriverInterface;

/**
 * M1 upload/share use cases (framework-free so tests run without Laravel).
 * Staging: local var/staging for ALL tiers; complete() does a single
 * streaming putFile into the tier driver (S3 has no append).
 */
final class UploadService
{
    public function __construct(
        private Store $store,
        private string $webBaseUrl,
        private mixed $scanner = null,
    ) {
    }

    /**
     * Normalize share options. Returns [passwordHash|null, maxViews|null, burnInt].
     *
     * @throws \InvalidArgumentException
     */
    public static function validateShareOptions(array $opts): array
    {
        $password = $opts['password'] ?? null;
        $hash = null;
        if ($password !== null && $password !== '') {
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 256) {
                throw new \InvalidArgumentException('password must be 8..256 chars');
            }
            $hash = password_hash($password, PASSWORD_ARGON2ID);
        }
        $maxViews = $opts['maxViews'] ?? null;
        if ($maxViews !== null) {
            $maxViews = (int) $maxViews;
            if ($maxViews < 1 || $maxViews > 100000) {
                throw new \InvalidArgumentException('maxViews must be 1..100000');
            }
        }
        $burn = !empty($opts['burn']) ? 1 : 0;
        if ($burn === 1) {
            $maxViews = 1; // burn-after-read ≡ single view
        }
        return [$hash, $maxViews, $burn];
    }

    /** @return array{uploadId: string, key: string, tier: string, expected: int} */
    public function reserve(string $tier, string $filename, int $size, string $mime): array
    {
        [$ok, $err] = Shares::validateInit(['tier' => $tier, 'filename' => $filename, 'size' => $size, 'mime' => $mime]);
        if (!$ok) {
            throw new \InvalidArgumentException($err);
        }
        $uploadId = 'up_' . Shares::newId(16);
        $key = sprintf('u/%s/%s/%s-%s', strtolower($tier), gmdate('Y-m-d'), $uploadId, Shares::safeBasename($filename));
        $now = time();
        $this->store->reserveUpload([
            'id' => $uploadId, 'tier' => $tier, 'filename' => $filename,
            'mime' => $mime, 'expected' => $size, 'storage_key' => $key, 'now' => $now,
        ]);
        touch($this->store->stagingPath($uploadId));
        return ['uploadId' => $uploadId, 'key' => $key, 'tier' => $tier, 'expected' => $size];
    }

    /** Append raw bytes; returns new offset. */
    public function append(string $uploadId, string $bytes): array
    {
        $up = $this->store->getUpload($uploadId);
        if ($up === null || $up['status'] !== 'open') {
            throw new \RuntimeException('unknown or closed upload');
        }
        if ($bytes === '') {
            return ['received' => (int) $up['received'], 'expected' => (int) $up['expected']];
        }
        if ((int) $up['received'] + strlen($bytes) > (int) $up['expected']) {
            throw new \RuntimeException('upload exceeds declared size');
        }
        file_put_contents($this->store->stagingPath($uploadId), $bytes, FILE_APPEND | LOCK_EX);
        $received = $this->store->addReceived($uploadId, strlen($bytes));
        return ['received' => $received, 'expected' => (int) $up['expected']];
    }

    /**
     * Finalize a staged upload: verify size, sniff mime, scan, single
     * streaming putFile into $driver, mint asset+share.
     *
     * @return array{shareId: string, shareUrl: string, tier: string, size: int, sha256: string, mime: string, expiresAt: string}
     */
    public function complete(string $uploadId, StorageDriverInterface $driver, array $opts = []): array
    {
        [$passwordHash, $maxViews, $burn] = self::validateShareOptions($opts);
        $up = $this->store->getUpload($uploadId);
        if ($up === null || $up['status'] !== 'open') {
            throw new \RuntimeException('unknown or closed upload');
        }
        $staging = $this->store->stagingPath($uploadId);
        $actual = is_file($staging) ? filesize($staging) : 0;
        if ($actual !== (int) $up['expected'] || (int) $up['received'] !== (int) $up['expected']) {
            throw new \RuntimeException(
                "incomplete upload: received {$up['received']}/{$up['expected']} bytes, staging {$actual}");
        }
        $this->scan($staging);
        $sha = hash_file('sha256', $staging);
        $mime = $this->resolveMime($staging, $up['mime']);
        $driver->putFile($up['storage_key'], $staging);
        $this->store->markComplete($uploadId);
        unlink($staging);
        return $this->mintShare($up['tier'], $up['storage_key'], $actual, $sha, $mime, $up['filename'],
            $passwordHash, $maxViews, $burn);
    }

    /**
     * Finalize a browser-direct-to-R2 upload: bytes already in R2,
     * server verifies existence + size before minting the share.
     * NOTE: inline malware scan covers the staged path only; direct uploads
     * are scanned by the async worker (M2b). See docs/threat-model.md.
     */
    public function completeL1(string $key, string $filename, int $size, string $mime, R2S3Driver $driver, array $opts = []): array
    {
        [$passwordHash, $maxViews, $burn] = self::validateShareOptions($opts);
        [$ok, $err] = Shares::validateInit(['tier' => 'L1', 'filename' => $filename, 'size' => $size, 'mime' => $mime]);
        if (!$ok) {
            throw new \InvalidArgumentException($err);
        }
        if (!$driver->exists($key)) {
            throw new \RuntimeException('object not found in L1 storage (browser upload missing?)');
        }
        $actual = $driver->size($key);
        if ($actual !== $size) {
            throw new \RuntimeException("size mismatch: declared {$size}, stored {$actual}");
        }
        $sha = hash('sha256', $driver->get($key));
        return $this->mintShare('L1', $key, $actual, $sha, $mime, $filename, $passwordHash, $maxViews, $burn);
    }

    /**
     * Gate a share row: ok | not-found | revoked | expired |
     * password-required | password-wrong. Views are consumed separately
     * (tryConsumeView) so redirects can check without counting.
     */
    public static function authorize(?array $shareRow, ?string $password): string
    {
        if ($shareRow === null) {
            return 'not-found';
        }
        if ($shareRow['revoked_at'] !== null) {
            return 'revoked';
        }
        if ((int) $shareRow['expires_at'] <= time()) {
            return 'expired';
        }
        if ($shareRow['password_hash'] !== null) {
            if ($password === null || $password === '') {
                return 'password-required';
            }
            if (!password_verify($password, $shareRow['password_hash'])) {
                return 'password-wrong';
            }
        }
        if ($shareRow['max_views'] !== null && (int) $shareRow['views'] >= (int) $shareRow['max_views']) {
            return 'exhausted';
        }
        return 'ok';
    }

    /** Delete blob via tier driver + drop metadata rows (burn / revoke / purge). */
    public function deleteShareBlob(string $shareId, callable $driverFor): bool
    {
        $row = $this->store->getShare($shareId);
        if ($row === null) {
            return false;
        }
        try {
            $driverFor($row['tier'])->delete($row['storage_key']);
        } catch (\Throwable) {
            // Blob already gone — still drop metadata.
        }
        $this->store->deleteShareTree($shareId);
        return true;
    }

    /** Capability-based GDPR manifest (share id + password when set). */
    public function export(string $shareId, ?string $password): ?array
    {
        $row = $this->store->getShare($shareId);
        if (self::authorize($row, $password) !== 'ok') {
            return null;
        }
        $meta = $this->meta($shareId);
        return [
            'share' => $meta,
            'asset' => ['size' => $row['size'], 'sha256' => $row['sha256'], 'mime' => $row['mime'],
                'filename' => $row['filename'], 'createdAt' => gmdate('c', (int) $row['created_at'])],
            'accessLog' => $this->store->accessLogFor($shareId),
            'retention' => 'expired/revoked shares are purged nightly (bytes <24h, rows with them)',
        ];
    }

    /** @return null|array{id,tier,badge,residency,filename,mime,size,sha256,expiresAt,expired,revoked,hasPassword,views,maxViews,burn} */
    public function meta(string $shareId): ?array
    {
        if (!Shares::validId($shareId)) {
            return null;
        }
        $row = $this->store->getShare($shareId);
        if ($row === null) {
            return null;
        }
        return [
            'id' => $row['id'],
            'tier' => $row['tier'],
            'badge' => Shares::badge($row['tier']),
            'residency' => $row['tier'] === 'L1' ? 'global-edge' : 'DE-only',
            'filename' => $row['filename'],
            'mime' => $row['mime'],
            'size' => (int) $row['size'],
            'sha256' => $row['sha256'],
            'expiresAt' => gmdate('c', (int) $row['expires_at']),
            'expired' => (int) $row['expires_at'] <= time(),
            'revoked' => $row['revoked_at'] !== null,
            'hasPassword' => $row['password_hash'] !== null,
            'views' => (int) $row['views'],
            'maxViews' => $row['max_views'] === null ? null : (int) $row['max_views'],
            'burn' => (int) $row['burn'] === 1,
        ];
    }

    /** Delete blobs + rows for expired shares. Returns deleted share count. */
    public function purgeExpired(callable $driverFor): int
    {
        $n = 0;
        foreach ($this->store->expiredShares(time()) as $row) {
            if ($this->deleteShareBlob($row['id'], $driverFor)) {
                $n++;
            }
        }
        return $n;
    }

    /** @return array{shareId,shareUrl,tier,size,sha256,mime,expiresAt} */
    private function mintShare(string $tier, string $storageKey, int $size, string $sha, string $mime, string $filename, ?string $passwordHash = null, ?int $maxViews = null, int $burn = 0): array
    {
        $now = time();
        $assetId = 'as_' . Shares::newId(16);
        $this->store->createAsset([
            'id' => $assetId, 'tier' => $tier, 'storage_key' => $storageKey,
            'size' => $size, 'sha256' => $sha, 'mime' => $mime, 'filename' => $filename, 'now' => $now,
        ]);
        $shareId = Shares::newId();
        $expires = Shares::defaultExpiryUnix($tier);
        $this->store->createShare([
            'id' => $shareId, 'asset_id' => $assetId, 'tier' => $tier, 'expires_at' => $expires, 'now' => $now,
            'password_hash' => $passwordHash, 'max_views' => $maxViews, 'burn' => $burn,
        ]);
        return [
            'shareId' => $shareId,
            'shareUrl' => rtrim($this->webBaseUrl, '/') . '/s/' . $shareId,
            'tier' => $tier, 'size' => $size, 'sha256' => $sha, 'mime' => $mime,
            'expiresAt' => gmdate('c', $expires),
        ];
    }

    /** Inline malware scan (skipped when no scanner configured). */
    private function scan(string $stagingPath): void
    {
        if ($this->scanner === null) {
            return;
        }
        $virus = ($this->scanner)($stagingPath);
        if ($virus !== null) {
            unlink($stagingPath);
            throw new \RuntimeException('rejected: malware detected (' . $virus . ')');
        }
    }

    private function resolveMime(string $path, string $declared): string
    {
        if ($declared !== '' && $declared !== 'application/octet-stream') {
            return $declared;
        }
        $sniffed = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
        return $sniffed === false ? $declared : $sniffed;
    }
}
