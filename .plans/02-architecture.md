# 02 — Architecture (PHP-first)

## Stack (proposed, to ratify)
- **API:** PHP 8.3+, Laravel 12 (API-only) + Sanctum (token) + Horizon/queue + Flysystem v3. Why Laravel: hiring pool, Flysystem S3/SFTP adapters mature, Horizon observability, OSS contributor familiarity.
  - Alternative: API Platform (Symfony). Keep decision in `05-open-questions.md`.
- **DB:** PostgreSQL 16 (metadata, shares, audit). Redis 7 (rate limit, view counters, signed-URL nonce).
- **Object backends:** `league/flysystem-aws-s3-v3` (covers R2 via endpoint override), `league/flysystem-sftp-v3`, `league/flysystem-local`.
- **Web:** TypeScript + Next.js 15 (App Router), Tailwind, tus-js-client (resumable) + S3 multipart direct-to-R2 for L1.
- **Jobs/workers:** thumbnails (ffmpeg/Imagick), ClamAV scan, expiry purge, audit shipper.
- **Auth:** email+password (argon2id) + TOTP 2FA; OIDC (Google/Apple) optional M3+; passkeys later.

## Core domain model
```
User(id, email, argon_hash, totp_secret?, created_at)
Asset(id, owner_id, tier L1|L2|L3, driver, bucket/path, size, mime, sha256, e2ee bool, created_at)
Share(id nanoid12, asset_id, password_hash?, expires_at?, max_views?, views, burn bool, revoked_at?)
AccessLog(id, share_id, ip_hash, ua_hash, at)  # append-only, PII-minimized
```

## Upload flows
- **L1 (R2):** `POST /v1/uploads/init` → presigned multipart URLs → browser uploads direct to R2 → `POST /v1/uploads/complete` (server verifies size/sha256, creates Asset+Share, dispatches scan+thumb). Fast, PHP never proxies bytes.
- **L2 (SFTP):** browser → `POST /v1/uploads/chunked` (tus, PHP streams to SFTP via Flysystem). PHP proxies; needs `client_max_body_size` + queue backpressure.
- **L3 (sovereign):** same tus endpoint but local disk adapter; single-node lock; optionalatar backup hook.
- All flows: magic-byte check → ClamAV → sha256 dedupe (per-user) → thumbnail job → share URL issued.

## Download/share flow
`GET /s/:id` → check revoked/expiry/views → password challenge if set → stream/X-redirect:
- L1: 302 to short-lived signed R2 URL (15 min) + `Content-Disposition`.
- L2/L3: PHP `X-Sendfile` / streamed response with `Range` support, rate-limited per IP.
- E2EE: server sends ciphertext only; decryption in browser via `#key` (never sent to server).

## Storage abstraction
```php
interface StorageDriverInterface {
  public function put(string $key, StreamInterface $s, array $meta): void;
  public function signedGetUrl(string $key, DateInterval $ttl): string;
  public function delete(string $key): void;
  public function tier(): Tier; // L1|L2|L3
}
// adapters: R2S3Driver, SftpVaultDriver, LocalSovereignDriver
// + ContractTests: put/get/delete/signed-url/expiry behavior identical
```

## API sketch (v1)
```
POST /v1/auth/register|login|2fa/verify
POST /v1/uploads/init|complete   (L1)  /  POST /v1/uploads/chunked (L2/L3, tus)
POST /v1/assets/:id/shares       (create share w/ expiry/password/maxViews/e2ee flag)
GET  /v1/shares/:id/meta         (expiry, tier badge, size — no bytes)
GET  /s/:id  → download/stream page (web) + direct bytes w/ Accept header
DELETE /v1/shares/:id | /v1/assets/:id
GET  /v1/me/exports (GDPR)
```

## Security / ops
- Rate limits: login 5/min, upload-init 30/h/user, share-guess 60/min/IP (Redis throttle).
- Headers: CSP strict, `X-Robots-Tag: noindex`, signed IDs unguessable.
- Observability: OpenTelemetry traces per tier, per-driver latency dashboards.

## Next actions
- [ ] Ratify Laravel vs API Platform
- [ ] Define OpenAPI v1 + tus vs S3-multipart decision matrix
- [ ] Contract-test matrix for 3 drivers (MinIO + `openssh-server` + tmpfs in CI)
