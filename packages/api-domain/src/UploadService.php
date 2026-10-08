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
    public function reserve(string $tier, string $filename, int $size, string $mime, ?string $ownerId = null, ?int $quotaBytes = null): array
    {
        [$ok, $err] = Shares::validateInit(['tier' => $tier, 'filename' => $filename, 'size' => $size, 'mime' => $mime]);
        if (!$ok) {
            throw new \InvalidArgumentException($err);
        }
        if ($ownerId !== null && $quotaBytes !== null
            && $this->store->userUsage($ownerId) + $size > $quotaBytes) {
            throw new \RuntimeException('quota exceeded');
        }
        $uploadId = 'up_' . Shares::newId(16);
        $key = sprintf('u/%s/%s/%s-%s', strtolower($tier), gmdate('Y-m-d'), $uploadId, Shares::safeBasename($filename));
        $now = time();
        $this->store->reserveUpload([
            'id' => $uploadId, 'tier' => $tier, 'filename' => $filename,
            'mime' => $mime, 'expected' => $size, 'storage_key' => $key, 'now' => $now,
        ], $ownerId);
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
        $e2ee = !empty($opts['e2ee']) ? 1 : 0;
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
        $this->scan($staging, $e2ee);
        $sha = hash_file('sha256', $staging);
        $mime = $e2ee === 1 ? $up['mime'] : $this->resolveMime($staging, $up['mime']);
        $driver->putFile($up['storage_key'], $staging);
        $thumbKey = $this->maybeThumb($driver, $up['storage_key'], $staging, $mime, $actual, $e2ee);
        $this->store->markComplete($uploadId);
        unlink($staging);
        $done = $this->mintShare($up['tier'], $up['storage_key'], $actual, $sha, $mime, $up['filename'],
            $passwordHash, $maxViews, $burn, $up['owner_id'] ?? null, $this->scanner !== null && $e2ee === 0 ? 1 : 0, $e2ee);
        if ($thumbKey !== null) {
            $assetId = $this->store->getShare($done['shareId'])['asset_id'];
            $this->store->setThumb($assetId, $thumbKey);
        }
        return $done;
    }

    /**
     * Make + store a thumbnail when eligible (plaintext image/video under the
     * size cap). Returns the thumb key or null. Never runs on ciphertext.
     */
    private function maybeThumb(StorageDriverInterface $driver, string $key, string $srcPath, string $mime, int $size, int $e2ee): ?string
    {
        if ($e2ee === 1) {
            return null;
        }
        $jpeg = Thumbnailer::make($srcPath, $mime, $size);
        if ($jpeg === null) {
            return null;
        }
        $thumbKey = $key . '.thumb.jpg';
        $driver->put($thumbKey, $jpeg, ['derived' => 'thumbnail']);
        return $thumbKey;
    }

    /**
     * Finalize a browser-direct-to-R2 upload: bytes already in R2,
     * server verifies existence + size before minting the share.
     * NOTE: inline malware scan covers the staged path only; direct uploads
     * are scanned by the async worker (M2b). See docs/threat-model.md.
     */
    public function completeL1(string $key, string $filename, int $size, string $mime, R2S3Driver $driver, array $opts = [], ?string $ownerId = null): array
    {
        [$passwordHash, $maxViews, $burn] = self::validateShareOptions($opts);
        $e2ee = !empty($opts['e2ee']) ? 1 : 0;
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
        $done = $this->mintShare('L1', $key, $actual, $sha, $mime, $filename, $passwordHash, $maxViews, $burn, $ownerId, 0, $e2ee);
        if ($e2ee === 0 && ($mime === null || str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'))
            && $actual <= Thumbnailer::MAX_SOURCE_BYTES) {
            // Direct uploads have no staging file: materialize via ranged reads.
            $tmp = sys_get_temp_dir() . '/pwf-dthumb-' . bin2hex(random_bytes(8)) . '.bin';
            $fh = fopen($tmp, 'wb');
            try {
                for ($off = 0; $off < $actual; $off += 1048576) {
                    fwrite($fh, $driver->readRange($key, $off, min(1048576, $actual - $off)));
                }
                fclose($fh);
                $thumbKey = $this->maybeThumb($driver, $key, $tmp, $mime, $actual, 0);
                if ($thumbKey !== null) {
                    $this->store->setThumb($this->store->getShare($done['shareId'])['asset_id'], $thumbKey);
                }
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
        }
        return $done;
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

    /** Delete blob (+ thumbnail) via tier driver + drop metadata rows (burn / revoke / purge). */
    public function deleteShareBlob(string $shareId, callable $driverFor): bool
    {
        $row = $this->store->getShare($shareId);
        if ($row === null) {
            return false;
        }
        try {
            $driver = $driverFor($row['tier']);
            $driver->delete($row['storage_key']);
            if (!empty($row['thumb_key'])) {
                $driver->delete($row['thumb_key']);
            }
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

    /** @return null|array{id,tier,badge,residency,filename,mime,size,sha256,expiresAt,expired,revoked,hasPassword,views,maxViews,burn,e2ee,hasThumbnail} */
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
            'e2ee' => (int) ($row['e2ee'] ?? 0) === 1,
            'hasThumbnail' => !empty($row['thumb_key']),
        ];
    }

    /** File an abuse report (unauthenticated, rate-limited at HTTP). @return report id */
    public function reportShare(string $shareId, string $reason, ?string $contact): string
    {
        $row = $this->store->getShare($shareId);
        if ($row === null || $row['revoked_at'] !== null) {
            throw new \RuntimeException('unknown share');
        }
        if (!in_array($reason, Store::REPORT_REASONS, true)) {
            throw new \InvalidArgumentException(
                'reason must be one of: ' . implode(',', Store::REPORT_REASONS));
        }
        if ($contact !== null && $contact !== '' &&
            (!filter_var($contact, FILTER_VALIDATE_EMAIL) || strlen($contact) > 254)) {
            throw new \InvalidArgumentException('invalid contact email');
        }
        $id = 'rp_' . Shares::newId(16);
        $this->store->createReport($id, $shareId, $reason, $contact ?: null, time());
        return $id;
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
    private function mintShare(string $tier, string $storageKey, int $size, string $sha, string $mime, string $filename, ?string $passwordHash = null, ?int $maxViews = null, int $burn = 0, ?string $ownerId = null, int $scanned = 0, int $e2ee = 0): array
    {
        $now = time();
        $assetId = 'as_' . Shares::newId(16);
        $this->store->createAsset([
            'id' => $assetId, 'tier' => $tier, 'storage_key' => $storageKey,
            'size' => $size, 'sha256' => $sha, 'mime' => $mime, 'filename' => $filename, 'now' => $now,
        ], $ownerId, $scanned, $e2ee);
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

    /** Inline malware scan (skipped when no scanner, or for ciphertext). */
    private function scan(string $stagingPath, int $e2ee = 0): void
    {
        if ($this->scanner === null || $e2ee === 1) {
            return;
        }
        $virus = ($this->scanner)($stagingPath);
        if ($virus !== null) {
            unlink($stagingPath);
            throw new \RuntimeException('rejected: malware detected (' . $virus . ')');
        }
    }

    // ---------- M2b: accounts, tus, quarantine ----------

    /** @return array{id: string} */
    public function register(string $email, string $password, int $quotaBytes): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new \InvalidArgumentException('invalid email');
        }
        if (strlen($password) < 12 || strlen($password) > 256) {
            throw new \InvalidArgumentException('account password must be 12..256 chars');
        }
        $id = 'us_' . Shares::newId(16);
        $this->store->createUser($id, $email, password_hash($password, PASSWORD_ARGON2ID), $quotaBytes, time());
        return ['id' => $id];
    }

    /** @return array{token: string} generic failure (no user enumeration) */
    public function login(string $email, string $password, string $tokenName = 'api'): array
    {
        $user = $this->store->findUserByEmail(strtolower(trim($email)));
        if ($user === null || !password_verify($password, $user['pw_hash'])) {
            throw new \RuntimeException('invalid credentials');
        }
        $token = 'pwf_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->store->createToken(hash('sha256', $token), $user['id'], substr($tokenName, 0, 80), time());
        return ['token' => $token];
    }

    /** @return null|array{id,email,quota_bytes,token_name} */
    public function userFromToken(?string $bearer): ?array
    {
        if ($bearer === null || !str_starts_with($bearer, 'pwf_')) {
            return null;
        }
        return $this->store->findUserByTokenHash(hash('sha256', $bearer), time());
    }

    /**
     * tus 1.0.0 Creation (subset): Upload-Length + Upload-Metadata required.
     * Metadata: "filename <b64>[,type <b64>]". Returns upload row for response.
     */
    public function tusCreate(string $tier, int $length, string $metadata, ?string $ownerId, ?int $quotaBytes): array
    {
        if ($length < 1 || $length > Shares::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Upload-Length must be 1..5GB');
        }
        $meta = self::parseTusMetadata($metadata);
        if (!isset($meta['filename'])) {
            throw new \InvalidArgumentException('Upload-Metadata must include filename');
        }
        $filename = base64_decode($meta['filename'], true);
        $mime = isset($meta['filetype']) ? base64_decode($meta['filetype'], true) : 'application/octet-stream';
        if ($filename === false || $filename === '') {
            throw new \InvalidArgumentException('invalid filename encoding');
        }
        if ($mime === false || $mime === '') {
            $mime = 'application/octet-stream';
        }
        return $this->reserve($tier, $filename, $length, $mime, $ownerId, $quotaBytes);
    }

    /** @return array<string,string> */
    public static function parseTusMetadata(string $header): array
    {
        $out = [];
        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $kv = preg_split('/\s+/', $pair, 2);
            if (count($kv) === 2 && preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/', $kv[0])) {
                $out[$kv[0]] = $kv[1];
            }
        }
        return $out;
    }

    /** HEAD offset info; throws on unknown/closed. */
    public function tusOffset(string $uploadId): array
    {
        $up = $this->store->getUpload($uploadId);
        if ($up === null || $up['status'] !== 'open') {
            throw new \RuntimeException('unknown or closed upload');
        }
        return ['offset' => (int) $up['received'], 'length' => (int) $up['expected']];
    }

    /** PATCH with strict offset match (409 on mismatch → client re-HEADs). */
    public function tusAppend(string $uploadId, int $offset, string $bytes): array
    {
        $cur = $this->tusOffset($uploadId);
        if ($offset !== $cur['offset']) {
            throw new \RuntimeException("offset mismatch: have {$cur['offset']}, got {$offset}");
        }
        return $this->append($uploadId, $bytes);
    }

    public function tusTerminate(string $uploadId): void
    {
        $staging = $this->store->stagingPath($uploadId);
        if (is_file($staging)) {
            unlink($staging);
        }
        $this->store->deleteUpload($uploadId);
    }

    /**
     * Quarantine worker (closes R1): stream every unscanned asset to a temp
     * file in 1 MiB slices, scan, quarantine on hit. E2EE ciphertext is
     * unscannable → recorded as skipped (2). @return array{scanned,quarantined,skipped}
     */
    public function quarantineUnscanned(callable $driverFor, callable $scanner, string $tmpDir, int $limit = 50): array
    {
        $done = 0;
        $bad = 0;
        $skipped = 0;
        foreach ($this->store->unscannedAssets($limit) as $asset) {
            if ((int) ($asset['e2ee'] ?? 0) === 1) {
                $this->store->markScanSkipped($asset['id']);
                $skipped++;
                continue;
            }
            $driver = $driverFor($asset['tier']);
            $tmp = $tmpDir . '/q-' . bin2hex(random_bytes(8)) . '.bin';
            try {
                $fh = fopen($tmp, 'wb');
                $size = $driver->size($asset['storage_key']);
                for ($off = 0; $off < $size; $off += 1048576) {
                    fwrite($fh, $driver->readRange($asset['storage_key'], $off, min(1048576, $size - $off)));
                }
                fclose($fh);
                $virus = $scanner($tmp);
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
            if ($virus !== null) {
                foreach ($this->store->sharesForAsset($asset['id']) as $share) {
                    $this->deleteShareBlob($share['id'], $driverFor);
                }
                $this->store->deleteAsset($asset['id']);
                $bad++;
                continue;
            }
            $this->store->markScanned($asset['id']);
            $done++;
        }
        return ['scanned' => $done, 'quarantined' => $bad, 'skipped' => $skipped];
    }

    /** Per-user GDPR manifest (authenticated). */
    public function exportUser(string $userId): array
    {
        $assets = $this->store->assetsFor($userId);
        $log = [];
        foreach ($assets as $a) {
            if ($a['share_id'] !== null) {
                foreach ($this->store->accessLogFor($a['share_id']) as $entry) {
                    $log[] = $entry + ['share_id' => $a['share_id']];
                }
            }
        }
        return ['assets' => $assets, 'accessLog' => $log,
            'retention' => 'expired/revoked shares are purged nightly (bytes <24h, rows with them)'];
    }

    /** Account erasure: delete all owned blobs, then cascade rows. Returns deleted share count. */
    public function deleteUserAccount(string $userId, callable $driverFor): int
    {
        $n = 0;
        foreach ($this->store->deleteUserCascade($userId) as $blob) {
            try {
                $driverFor($blob['tier'])->delete($blob['storage_key']);
            } catch (\Throwable) {
            }
            $n++;
        }
        return $n;
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
