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
            SQL);
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
        $pdo->exec(<<<'SQL'
            CREATE TABLE uploads(
              id TEXT PRIMARY KEY, tier TEXT NOT NULL, filename TEXT NOT NULL,
              mime TEXT NOT NULL, expected INTEGER NOT NULL, received INTEGER NOT NULL DEFAULT 0,
              storage_key TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open', created_at INTEGER NOT NULL);
            CREATE TABLE assets(
              id TEXT PRIMARY KEY, tier TEXT NOT NULL, storage_key TEXT NOT NULL,
              size INTEGER NOT NULL, sha256 TEXT NOT NULL, mime TEXT NOT NULL,
              filename TEXT NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE shares(
              id TEXT PRIMARY KEY, asset_id TEXT NOT NULL REFERENCES assets(id),
              tier TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at INTEGER NOT NULL);
            CREATE INDEX idx_shares_expires ON shares(expires_at);
            SQL);
        return $s;
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
            'INSERT INTO shares(id,asset_id,tier,expires_at,created_at) VALUES(:id,:asset_id,:tier,:expires_at,:now)');
        $st->execute($row);
    }

    /** Share + asset joined; null when unknown. Caller checks expiry → 410. */
    public function getShare(string $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT s.id,s.tier,s.expires_at,s.created_at,a.id AS asset_id,a.storage_key,a.size,a.sha256,a.mime,a.filename
             FROM shares s JOIN assets a ON a.id=s.asset_id WHERE s.id=:id');
        $st->execute(['id' => $id]);
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
        $st = $this->pdo->prepare(
            'SELECT s.id,a.tier,a.storage_key FROM shares s JOIN assets a ON a.id=s.asset_id WHERE s.expires_at<=:now');
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

    /** Test helper: force expiry. */
    public function setShareExpiry(string $shareId, int $expiresAt): void
    {
        $st = $this->pdo->prepare('UPDATE shares SET expires_at=:e WHERE id=:id');
        $st->execute(['e' => $expiresAt, 'id' => $shareId]);
    }
}
