<?php

declare(strict_types=1);

namespace PrivateWf\Storage;

interface StorageDriverInterface
{
    public function tier(): Tier;

    /** Store bytes under $key (opaque object key, e.g. "u/<uid>/<sha256>"). */
    public function put(string $key, string $contents, array $meta = []): void;

    /** @throws \RuntimeException when key does not exist */
    public function get(string $key): string;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /** Short-lived read URL. Must embed expiry; must not leak credentials. */
    public function signedGetUrl(string $key, int $ttlSeconds = 900): string;
}
