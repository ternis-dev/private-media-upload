<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

interface StorageDriverInterface
{
    public function tier(): Tier;

    /** Store bytes under $key (opaque object key, e.g. "u/<uid>/<sha256>"). */
    public function put(string $key, string $contents, array $meta = []): void;

    /** Store file at $path under $key without loading it into memory. */
    public function putFile(string $key, string $path, array $meta = []): void;

    /**
     * Read up to $length bytes at $offset (for Range streaming without
     * loading whole objects). Returns fewer bytes at EOF.
     *
     * @throws \RuntimeException when key does not exist
     */
    public function readRange(string $key, int $offset, int $length): string;

    /** @throws \RuntimeException when key does not exist */
    public function get(string $key): string;

    /** @throws \RuntimeException when key does not exist */
    public function size(string $key): int;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /** Short-lived read URL. Must embed expiry; must not leak credentials. */
    public function signedGetUrl(string $key, int $ttlSeconds = 900): string;

    /** Verify a URL produced by signedGetUrl (HMAC tiers; SigV4 verifies itself). */
    public function verifySignedUrl(string $key, int $expires, string $sig): bool;
}
