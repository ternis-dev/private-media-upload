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

    public static function varDir(): string
    {
        return Env::get('VAR_DIR') ?: __DIR__ . '/../var';
    }

    /** Env-wired open: DB_DSN (pgsql) > DB_PATH=:memory: (tests) > sqlite file. */
    public static function openFromEnv(): self
    {
        $varDir = self::varDir();
        $dsn = Env::get('DB_DSN');
        if ($dsn !== '') {
            $pdo = new PDO($dsn, Env::get('DB_USER'), Env::get('DB_PASS'), options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::migrate($pdo);
            return new self($pdo, $varDir);
        }
        if (Env::get('DB_PATH') === ':memory:') {
            return self::memory($varDir . '-test-' . bin2hex(random_bytes(4)));
        }
        return self::open(Env::get('DB_PATH') ?: $varDir . '/privatewf.sqlite', $varDir);
    }

    /** Inject an existing PDO (tests, Laravel). Runs migrations. */
    public static function fromPdo(\PDO $pdo, string $varDir): self
    {
        self::ensureDirs($varDir);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        self::migrate($pdo);
        return new self($pdo, $varDir);
    }

    private static function ensureDirs(string $varDir): void
    {
        foreach ([$varDir, $varDir . '/staging'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
    }

    public static function isPgsql(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    public static function open(string $dbPath, string $varDir): self
    {
        // Directories first: PDO sqlite cannot create missing parent dirs,
        // and argument evaluation would construct PDO before fromPdo() runs.
        self::ensureDirs($varDir);
        return self::fromPdo(new PDO('sqlite:' . $dbPath), $varDir);
    }

    /** :memory: store for tests. */
    public static function memory(string $varDir): self
    {
        return self::fromPdo(new PDO('sqlite::memory:'), $varDir);
    }

    private static function migrate(PDO $pdo): void
    {
        $pgsql = self::isPgsql($pdo);
        if (!$pgsql) {
            $pdo->exec('PRAGMA journal_mode=WAL');
        }
        $autoId = $pgsql ? 'id SERIAL PRIMARY KEY' : 'id INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec(<<<SQL
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
              {$autoId}, share_id TEXT NOT NULL, ip_hash TEXT NOT NULL,
              ua_hash TEXT NOT NULL, result TEXT NOT NULL, at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS idx_access_share ON access_log(share_id);
            CREATE TABLE IF NOT EXISTS ratelimits(
              key TEXT PRIMARY KEY, count INTEGER NOT NULL, window_start INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS users(
              id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, pw_hash TEXT NOT NULL,
              quota_bytes INTEGER NOT NULL, created_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS tokens(
              token_hash TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES users(id),
              name TEXT NOT NULL, created_at INTEGER NOT NULL, last_used INTEGER NULL);
            CREATE INDEX IF NOT EXISTS idx_tokens_user ON tokens(user_id);
            SQL);
        // Columns on pre-existing DBs (both drivers).
        $existing = static function (string $table) use ($pdo, $pgsql): array {
            if ($pgsql) {
                $st = $pdo->prepare(
                    'SELECT column_name FROM information_schema.columns WHERE table_name=:t');
                $st->execute(['t' => $table]);
                return $st->fetchAll(PDO::FETCH_COLUMN);
            }
            return $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        };
        foreach ([
            'shares' => ['password_hash' => 'TEXT NULL', 'max_views' => 'INTEGER NULL',
                'views' => 'INTEGER NOT NULL DEFAULT 0', 'burn' => 'INTEGER NOT NULL DEFAULT 0',
                'revoked_at' => 'INTEGER NULL'],
            'uploads' => ['owner_id' => 'TEXT NULL'],
            'assets' => ['owner_id' => 'TEXT NULL', 'scanned' => 'INTEGER NOT NULL DEFAULT 0',
                'e2ee' => 'INTEGER NOT NULL DEFAULT 0'],
        ] as $table => $cols) {
            $have = $existing($table);
            foreach ($cols as $col => $ddl) {
                if (!in_array($col, $have, true)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$ddl}");
                }
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

    public function reserveUpload(array $row, ?string $ownerId = null): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO uploads(id,tier,filename,mime,expected,received,storage_key,status,created_at,owner_id)
             VALUES(:id,:tier,:filename,:mime,:expected,0,:storage_key,\'open\',:now,:owner)');
        $st->execute($row + ['owner' => $ownerId]);
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

    public function createAsset(array $row, ?string $ownerId = null, int $scanned = 0, int $e2ee = 0): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO assets(id,tier,storage_key,size,sha256,mime,filename,created_at,owner_id,scanned,e2ee)
             VALUES(:id,:tier,:storage_key,:size,:sha256,:mime,:filename,:now,:owner,:scanned,:e2ee)');
        $st->execute($row + ['owner' => $ownerId, 'scanned' => $scanned, 'e2ee' => $e2ee]);
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
            'SELECT s.*,a.id AS asset_id,a.storage_key,a.size,a.sha256,a.mime,a.filename,a.scanned,a.e2ee
             FROM shares s JOIN assets a ON a.id=s.asset_id WHERE s.id=:id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Same join by storage key (blob route only knows the key). */
    public function getShareByKey(string $storageKey): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT s.*,a.id AS asset_id,a.storage_key,a.size,a.sha256,a.mime,a.filename,a.scanned,a.e2ee
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

    // ---------- M2b: accounts, ownership, quarantine ----------

    public function createUser(string $id, string $email, string $pwHash, int $quotaBytes, int $now): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO users(id,email,pw_hash,quota_bytes,created_at) VALUES(:id,:email,:pw,:quota,:now)');
        try {
            $st->execute(['id' => $id, 'email' => $email, 'pw' => $pwHash, 'quota' => $quotaBytes, 'now' => $now]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('email taken', 0, $e);
        }
    }

    public function findUserByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE email=:e');
        $st->execute(['e' => $email]);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function createToken(string $tokenHash, string $userId, string $name, int $now): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO tokens(token_hash,user_id,name,created_at) VALUES(:h,:u,:n,:now)');
        $st->execute(['h' => $tokenHash, 'u' => $userId, 'n' => $name, 'now' => $now]);
    }

    /** @return null|array{id,email,quota_bytes,token_name} */
    public function findUserByTokenHash(string $tokenHash, int $now): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT u.id,u.email,u.quota_bytes,t.name AS token_name FROM tokens t
             JOIN users u ON u.id=t.user_id WHERE t.token_hash=:h');
        $st->execute(['h' => $tokenHash]);
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }
        $up = $this->pdo->prepare('UPDATE tokens SET last_used=:now WHERE token_hash=:h');
        $up->execute(['now' => $now, 'h' => $tokenHash]);
        return $row;
    }

    public function revokeToken(string $tokenHash): void
    {
        $st = $this->pdo->prepare('DELETE FROM tokens WHERE token_hash=:h');
        $st->execute(['h' => $tokenHash]);
    }

    public function revokeUserTokens(string $userId): void
    {
        $st = $this->pdo->prepare('DELETE FROM tokens WHERE user_id=:u');
        $st->execute(['u' => $userId]);
    }

    /** Bytes owned: stored assets + open staging reservations. */
    public function userUsage(string $userId): int
    {
        $st = $this->pdo->prepare('SELECT COALESCE(SUM(size),0) FROM assets WHERE owner_id=:u');
        $st->execute(['u' => $userId]);
        $assets = (int) $st->fetchColumn();
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(expected),0) FROM uploads WHERE owner_id=:u AND status='open'");
        $st->execute(['u' => $userId]);
        return $assets + (int) $st->fetchColumn();
    }

    /** @return list<array{id,filename,mime,size,created_at,tier,storage_key,share_id,expires_at}> */
    public function assetsFor(string $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id,a.filename,a.mime,a.size,a.created_at,a.tier,a.storage_key,s.id AS share_id,s.expires_at
             FROM assets a LEFT JOIN shares s ON s.asset_id=a.id
             WHERE a.owner_id=:u ORDER BY a.created_at DESC');
        $st->execute(['u' => $userId]);
        return $st->fetchAll();
    }

    /** @return list<array{id,tier,storage_key,size,filename,e2ee}> */
    public function unscannedAssets(int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT id,tier,storage_key,size,filename,e2ee FROM assets WHERE scanned=0 ORDER BY created_at ASC LIMIT :lim');
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function markScanned(string $assetId): void
    {
        $st = $this->pdo->prepare('UPDATE assets SET scanned=1 WHERE id=:id');
        $st->execute(['id' => $assetId]);
    }

    /** Ciphertext is unscannable: record skip (2) so the worker doesn't loop. */
    public function markScanSkipped(string $assetId): void
    {
        $st = $this->pdo->prepare('UPDATE assets SET scanned=2 WHERE id=:id');
        $st->execute(['id' => $assetId]);
    }

    /** @return list<array{id: string}> shares pointing at an asset */
    public function sharesForAsset(string $assetId): array
    {
        $st = $this->pdo->prepare('SELECT id FROM shares WHERE asset_id=:a');
        $st->execute(['a' => $assetId]);
        return $st->fetchAll();
    }

    /**
     * Full account erasure: returns share ids whose blobs the caller must
     * delete, then drops tokens, assets, shares, staging rows and the user.
     *
     * @return list<array{id: string, tier: string, storage_key: string}>
     */
    public function deleteUserCascade(string $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.id,a.tier,a.storage_key FROM assets a LEFT JOIN shares s ON s.asset_id=a.id
             WHERE a.owner_id=:u');
        $st->execute(['u' => $userId]);
        $blobs = $st->fetchAll();
        $this->pdo->beginTransaction();
        try {
            foreach (['tokens' => 'user_id', 'shares' => null, 'assets' => 'owner_id'] as $table => $col) {
                if ($table === 'shares') {
                    $this->pdo->prepare(
                        'DELETE FROM shares WHERE asset_id IN (SELECT id FROM assets WHERE owner_id=:u)')
                        ->execute(['u' => $userId]);
                } else {
                    $this->pdo->prepare("DELETE FROM {$table} WHERE {$col}=:u")->execute(['u' => $userId]);
                }
            }
            $this->pdo->prepare("DELETE FROM uploads WHERE owner_id=:u")->execute(['u' => $userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id=:u')->execute(['u' => $userId]);
            $this->pdo->commit();
            return array_values(array_filter($blobs, static fn ($b) => $b['id'] !== null));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteUpload(string $uploadId): void
    {
        $st = $this->pdo->prepare('DELETE FROM uploads WHERE id=:id');
        $st->execute(['id' => $uploadId]);
    }

    public function deleteAsset(string $assetId): void
    {
        $st = $this->pdo->prepare('DELETE FROM assets WHERE id=:id');
        $st->execute(['id' => $assetId]);
    }

    /** Test helper: force expiry. */
    public function setShareExpiry(string $shareId, int $expiresAt): void
    {
        $st = $this->pdo->prepare('UPDATE shares SET expires_at=:e WHERE id=:id');
        $st->execute(['e' => $expiresAt, 'id' => $shareId]);
    }
}
