<?php

declare(strict_types=1);

namespace PrivateWf\Api;

use PDO;

/**
 * M1 metadata store (SQLite; pgsql migration with Laravel in M2+ per ADR-0001).
 * Tables: uploads (open reservations + staging offsets), assets, shares.
 */
final class Store
{
    private function __construct(
        private PDO $pdo,
        public readonly string $varDir,
    ) {
    }

    public static function open(string $dbPath, string $varDir): self
    {
        foreach ([$varDir, $varDir . '/staging'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        $pdo = new PDO('sqlite:' . $dbPath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        self::migrate($pdo);
        return new self($pdo, $varDir);
    }

    /** :memory: store for tests. */
    public static function memory(string $varDir): self
    {
        if (!is_dir($varDir . '/staging')) {
            mkdir($varDir . '/staging', 0700, true);
        }
        $pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $s = new self($pdo, $varDir);
        self::migrate($pdo);
        return $s;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS uploads(
              id TEXT PRIMARY KEY, tier TEXT NOT NULL, filename TEXT NOT NULL,
              mime TEXT NOT NULL, expected INTEGER NOT NULL, received INTEGER NOT NULL DEFAULT 0,
              storage_key TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS assets(
              id TEXT PRIMARY KEY, tier TEXT NOT NULL, storage_key TEXT NOT NULL,
              size INTEGER NOT NULL, sha256 TEXT NOT NULL, mime TEXT NOT NULL,
              filename TEXT NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS shares(
              id TEXT PRIMARY KEY, asset_id TEXT NOT NULL REFERENCES assets(id),
              tier TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_shares_expires ON shares(expires_at);
            CREATE TABLE IF NOT EXISTS access_log(
              id INTEGER PRIMARY KEY AUTOINCREMENT, share_id TEXT NOT NULL, ip_hash TEXT NOT NULL,
              ua_hash TEXT NOT NULL, result TEXT NOT NULL, at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_access_share ON access_log(share_id);
            CREATE TABLE IF NOT EXISTS ratelimits(
              key TEXT PRIMARY KEY, count INTEGER NOT NULL, window_start INTEGER NOT NULL);
            SQL);
        // M2a columns on pre-existing DBs.
        foreach ([
            'password_hash' => 'TEXT NULL', 'max_views' => 'INTEGER NULL',
            'views' => 'INTEGER NOT NULL DEFAULT 0', 'burn' => 'INTEGER NOT NULL DEFAULT 0',
            'revoked_at' => 'INTEGER NULL',
        ] as $col => $ddl) {
            $cols = $pdo->query('PRAGMA table_info(shares)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE shares ADD COLUMN {$col} {$ddl}");
            }
        }
    }

    public function stagingPath(string $uploadId): string
    {
        if (!preg_match('/^up_[0-9A-Za-z]{8,32}$/', $uploadId)) {
            throw new \InvalidArgumentException('invalid upload id');
        }
        return $this->varDir . '/staging/' . $uploadId . '.part';
    }

    public function reserveUpload(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO uploads(id,tier,filename,mime,expected,received,storage_key,status,created_at)
             VALUES(:id,:tier,:filename,:mime,:expected,0,:storage_key,\'open\',:now)');
        $st->execute($row);
    }

    public function getUpload(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM uploads WHERE id=:id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function addReceived(string $id, int $bytes): int
    {
        $this->pdo->beginTransaction();
        try {
            $up = $this->getUpload($id);
            if ($up === null || $up['status'] !== 'open') {
                $this->pdo->rollBack();
                throw new \RuntimeException('unknown or closed upload');
            }
            $received = (int) $up['received'] + $bytes;
            if ($received > (int) $up['expected']) {
                $this->pdo->rollBack();
                throw new \RuntimeException('upload exceeds declared size');
            }
            $st = $this->pdo->prepare('UPDATE uploads SET received=:r WHERE id=:id');
            $st->execute(['r' => $received, 'id' => $id]);
            $this->pdo->commit();
            return $received;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markComplete(string $id): void
    {
        $st = $this->pdo->prepare("UPDATE uploads SET status='complete' WHERE id=:id");
        $st->execute(['id' => $id]);
    }

    public function createAsset(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO assets(id,tier,storage_key,size,sha256,mime,filename,created_at)
             VALUES(:id,:tier,:storage_key,:size,:sha256,:mime,:filename,:now)');
        $st->execute($row);
    }

    public function createShare(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO shares(id,asset_id,tier,expires_at,created_at,password_hash,max_views,views,burn)
             VALUES(:id,:asset_id,:tier,:expires_at,:now,:password_hash,:max_views,0,:burn)');
        $st->execute($row + ['password_hash' => null, 'max_views' => null, 'burn' => 0]);
    }

    /** Share + asset joined; null when unknown. Caller checks expiry/revoke/password. */
    public function getShare(string $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT s.*,a.id AS asset_id,a.storage_key,a.size,a.sha256,a.mime,a.filename
             FROM shares s JOIN assets a ON a.id=s.asset_id WHERE s.id=:id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Same join by storage key (blob route only knows the key). */
    public function getShareByKey(string $storageKey): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT s.*,a.id AS asset_id,a.storage_key,a.size,a.sha256,a.mime,a.filename
             FROM shares s JOIN assets a ON a.id=s.asset_id WHERE a.storage_key=:k');
        $st->execute(['k' => $storageKey]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Find asset by storage key (for blob Content-Type). */
    public function getAssetByKey(string $storageKey): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM assets WHERE storage_key=:k');
        $st->execute(['k' => $storageKey]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array{id: string, tier: string, storage_key: string}> */
    public function expiredShares(int $now): array
    {
        // Expired shares + revoked ones (revoke deletes bytes now, rows here).
        $st = $this->pdo->prepare(
            'SELECT s.id,a.tier,a.storage_key FROM shares s JOIN assets a ON a.id=s.asset_id
             WHERE s.expires_at<=:now OR s.revoked_at IS NOT NULL');
        $st->execute(['now' => $now]);
        return $st->fetchAll();
    }

    public function deleteShareTree(string $shareId): void
    {
        $share = $this->getShare($shareId);
        if ($share === null) {
            return;
        }
        $st = $this->pdo->prepare('DELETE FROM shares WHERE id=:id');
        $st->execute(['id' => $shareId]);
        $st = $this->pdo->prepare('DELETE FROM assets WHERE id=:id');
        $st->execute(['id' => $share['asset_id']]);
    }

    /**
     * Atomically consume one view. Returns ok + whether the share is now
     * spent (burn or max_views reached → caller must delete blob + rows).
     */
    public function tryConsumeView(string $shareId): array
    {
        $this->pdo->beginTransaction();
        try {
            $up = $this->pdo->prepare(
                'UPDATE shares SET views=views+1 WHERE id=:id AND revoked_at IS NULL
                 AND (max_views IS NULL OR views<max_views)');
            $up->execute(['id' => $shareId]);
            if ($up->rowCount() === 0) {
                $this->pdo->rollBack();
                $row = $this->getShare($shareId);
                if ($row === null) {
                    return ['ok' => false, 'reason' => 'not-found'];
                }
                if ($row['revoked_at'] !== null) {
                    return ['ok' => false, 'reason' => 'revoked'];
                }
                return ['ok' => false, 'reason' => 'exhausted'];
            }
            $st = $this->pdo->prepare('SELECT burn,max_views,views FROM shares WHERE id=:id');
            $st->execute(['id' => $shareId]);
            $row = $st->fetch();
            $this->pdo->commit();
            $spent = (int) $row['burn'] === 1
                || ($row['max_views'] !== null && (int) $row['views'] >= (int) $row['max_views']);
            return ['ok' => true, 'spent' => $spent];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Revoke now: link dies (410), bytes deleted by caller, rows swept by purge. */
    public function revokeShare(string $shareId, int $now): ?array
    {
        $row = $this->getShare($shareId);
        if ($row === null || $row['revoked_at'] !== null) {
            return $row;
        }
        $st = $this->pdo->prepare('UPDATE shares SET revoked_at=:now WHERE id=:id');
        $st->execute(['now' => $now, 'id' => $shareId]);
        return $this->getShare($shareId);
    }

    /** Fixed-window limiter. @return array{allowed: bool, remaining: int, reset: int} */
    public function rateHit(string $key, int $limit, int $windowSec, int $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare('SELECT count,window_start FROM ratelimits WHERE key=:k');
            $st->execute(['k' => $key]);
            $row = $st->fetch();
            if ($row === false || (int) $row['window_start'] + $windowSec <= $now) {
                $st = $this->pdo->prepare(
                    'INSERT INTO ratelimits(key,count,window_start) VALUES(:k,1,:now)
                     ON CONFLICT(key) DO UPDATE SET count=1,window_start=:now');
                $st->execute(['k' => $key, 'now' => $now]);
                $this->pdo->commit();
                return ['allowed' => true, 'remaining' => $limit - 1, 'reset' => $now + $windowSec];
            }
            if ((int) $row['count'] >= $limit) {
                $this->pdo->commit();
                return ['allowed' => false, 'remaining' => 0, 'reset' => (int) $row['window_start'] + $windowSec];
            }
            $st = $this->pdo->prepare('UPDATE ratelimits SET count=count+1 WHERE key=:k');
            $st->execute(['k' => $key]);
            $this->pdo->commit();
            return ['allowed' => true, 'remaining' => $limit - (int) $row['count'] - 1,
                'reset' => (int) $row['window_start'] + $windowSec];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function logAccess(string $shareId, string $ipHash, string $uaHash, string $result, int $at): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO access_log(share_id,ip_hash,ua_hash,result,at) VALUES(:s,:ip,:ua,:r,:at)');
        $st->execute(['s' => $shareId, 'ip' => $ipHash, 'ua' => $uaHash, 'r' => $result, 'at' => $at]);
    }

    /** PII-minimized audit trail for one share (GDPR export). */
    public function accessLogFor(string $shareId): array
    {
        $st = $this->pdo->prepare(
            'SELECT ip_hash,ua_hash,result,at FROM access_log WHERE share_id=:s ORDER BY at ASC');
        $st->execute(['s' => $shareId]);
        return $st->fetchAll();
    }

    /** Test helper: force expiry. */
    public function setShareExpiry(string $shareId, int $expiresAt): void
    {
        $st = $this->pdo->prepare('UPDATE shares SET expires_at=:e WHERE id=:id');
        $st->execute(['e' => $expiresAt, 'id' => $shareId]);
    }
}
