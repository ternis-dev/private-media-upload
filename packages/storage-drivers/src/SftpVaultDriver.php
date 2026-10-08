<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * L2 — Provider Vault DE (SFTP/Storage Box, single location, no CDN).
 *
 * Two modes (same pattern as R2S3Driver):
 * - Real (M2a): SFTP_L2_HOST set → phpseclib SFTP against Hetzner box / vault.
 * - Emulation: local dir so contract tests run with zero infrastructure.
 *
 * Reads always proxy through PHP (readRange, offset/length natively) —
 * there is deliberately no CDN copy: single location is the privacy feature.
 */
final class SftpVaultDriver implements StorageDriverInterface
{
    private ?SFTP $sftp = null;

    public function __construct(
        private readonly string $emulationRoot = '',
        private readonly string $baseUrl = 'http://localhost:8000',
        private readonly string $hmacSecret = 'changeme-sovereign',
        private readonly string $host = '',
        private readonly int $port = 22,
        private readonly string $user = '',
        private readonly string $password = '',
        private readonly string $keyFile = '',
        private readonly string $keyPassphrase = '',
        private readonly string $root = '/vault',
    ) {
        if (!$this->isReal()) {
            $inner = new LocalSovereignDriver($this->emulationRoot . '/vault-de', $this->baseUrl, $this->hmacSecret);
            $this->emuInner = $inner;
        }
    }

    private ?LocalSovereignDriver $emuInner = null;

    public static function fromEnv(): self
    {
        return new self(
            emulationRoot: getenv('SFTP_L2_EMU_ROOT') ?: sys_get_temp_dir() . '/pwf-l2-emu',
            baseUrl: getenv('APP_URL') ?: 'http://localhost:8000',
            hmacSecret: getenv('HMAC_SECRET') ?: 'changeme-sovereign',
            host: getenv('SFTP_L2_HOST') ?: '',
            port: (int) (getenv('SFTP_L2_PORT') ?: 22),
            user: getenv('SFTP_L2_USER') ?: '',
            password: getenv('SFTP_L2_PASSWORD') ?: '',
            keyFile: getenv('SFTP_L2_KEY_FILE') ?: '',
            keyPassphrase: getenv('SFTP_L2_KEY_PASSPHRASE') ?: '',
            root: getenv('SFTP_L2_ROOT') ?: '/vault',
        );
    }

    public function isReal(): bool
    {
        return $this->host !== '' && $this->user !== '';
    }

    public function tier(): Tier
    {
        return Tier::L2;
    }

    public function put(string $key, string $contents, array $meta = []): void
    {
        if (!$this->isReal()) {
            $this->emuInner->put($key, $contents, $meta);
            return;
        }
        $this->ensureDir(dirname($key));
        if (!$this->conn()->put($this->remote($key), $contents)) {
            throw new \RuntimeException("sftp put failed: {$key}");
        }
    }

    public function putFile(string $key, string $path, array $meta = []): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("no such file: {$path}");
        }
        if (!$this->isReal()) {
            $this->emuInner->putFile($key, $path, $meta);
            return;
        }
        $this->ensureDir(dirname($key));
        if (!$this->conn()->put($this->remote($key), $path, SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException("sftp putFile failed: {$key}");
        }
    }

    public function get(string $key): string
    {
        if (!$this->isReal()) {
            return $this->emuInner->get($key);
        }
        $data = $this->conn()->get($this->remote($key));
        if ($data === false) {
            throw new \RuntimeException("not found: {$key}");
        }
        return $data;
    }

    public function readRange(string $key, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 1) {
            throw new \InvalidArgumentException('invalid range');
        }
        if (!$this->isReal()) {
            return $this->emuInner->readRange($key, $offset, $length);
        }
        $data = $this->conn()->get($this->remote($key), false, $offset, $length);
        if ($data === false) {
            throw new \RuntimeException("not found: {$key}");
        }
        return $data;
    }

    public function exists(string $key): bool
    {
        if (!$this->isReal()) {
            return $this->emuInner->exists($key);
        }
        return $this->conn()->file_exists($this->remote($key));
    }

    public function size(string $key): int
    {
        if (!$this->isReal()) {
            return $this->emuInner->size($key);
        }
        $size = $this->conn()->filesize($this->remote($key));
        if ($size === false) {
            throw new \RuntimeException("not found: {$key}");
        }
        return $size;
    }

    public function delete(string $key): void
    {
        if (!$this->isReal()) {
            $this->emuInner->delete($key);
            return;
        }
        $this->conn()->delete($this->remote($key));
    }

    public function signedGetUrl(string $key, int $ttlSeconds = 900): string
    {
        if (!$this->isReal()) {
            return $this->emuInner->signedGetUrl($key, $ttlSeconds);
        }
        $expires = time() + $ttlSeconds;
        $sig = hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret);
        return rtrim($this->baseUrl, '/') . '/v1/blobs/' . rawurlencode($key)
            . '?expires=' . $expires . '&sig=' . $sig;
    }

    public function verifySignedUrl(string $key, int $expires, string $sig): bool
    {
        if (!$this->isReal()) {
            return $this->emuInner->verifySignedUrl($key, $expires, $sig);
        }
        if ($expires < time()) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $key . '|' . $expires, $this->hmacSecret), $sig);
    }

    /** Emulation-only: local path for tests. Real mode streams via readRange. */
    public function localPath(string $key): string
    {
        if ($this->isReal()) {
            throw new \LogicException('no local path in real SFTP mode (use readRange)');
        }
        return $this->emuInner->localPath($key);
    }

    private function remote(string $key): string
    {
        if (str_contains($key, '..')) {
            throw new \InvalidArgumentException('invalid key');
        }
        return rtrim($this->root, '/') . '/' . ltrim($key, '/');
    }

    private function ensureDir(string $keyDir): void
    {
        if ($keyDir === '' || $keyDir === '.') {
            return;
        }
        $this->conn()->mkdir($this->remote($keyDir), -1, true);
    }

    private function conn(): SFTP
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }
        $sftp = new SFTP($this->host, $this->port);
        $sftp->setTimeout(10);
        $credential = $this->password;
        if ($this->keyFile !== '') {
            $credential = PublicKeyLoader::load(
                file_get_contents($this->keyFile) ?: '',
                $this->keyPassphrase !== '' ? $this->keyPassphrase : false);
        }
        if (!$sftp->login($this->user, $credential)) {
            throw new \RuntimeException("sftp login failed: {$this->user}@{$this->host}");
        }
        return $this->sftp = $sftp;
    }
}
