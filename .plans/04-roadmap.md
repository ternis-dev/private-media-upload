# 04 — Roadmap (M0 → M4)

## M0 — Repo bootstrap (this week) — DoD
- [x] `git init`, README, LICENSE (AGPL-3.0-or-later), `.plans/00–05`
- [x] `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `.env.example`
- [x] `infra/docker/compose.yml` (pgsql, redis, minio, sftp, mailpit + api/web dev services) `config -q` green
- [x] CI skeleton (`ci.yml`: storage-drivers + api + web + compose) — phpunit 14+6 green, node --test 2 green
- [x] M0 code slice: `packages/storage-drivers` (Tier L1/L2/L3 + 3 drivers + contract tests), `apps/api` lean router (health/tiers/upload-init, Laravel lands M1 per ADR-0001), `apps/web` tier picker + share stub, `packages/shared-types/openapi.yaml` v0.0.1-M0
- DoD: fresh clone → `docker compose up` → hello pages for api+web.

## M1 — L1 + L3 slices ✅ DONE (lean-PHP M1; Laravel migration moved to M2+)
- [x] L1 init/complete: real SigV4 presigned PUT/GET via aws-sdk (endpoint override covers R2/MinIO/Hetzner), emulation fallback for zero-infra dev; `ensureBucket`; live roundtrip test env-gated (CI runs MinIO)
- [x] L3 chunked upload (reserve → append → complete) + HMAC signed streaming + Range (206) + `DE-only` badge in meta
- [x] SQLite metadata (uploads/assets/shares), `bin/purge.php`, expiry 410 → purge → bytes gone (proven live: 100 MB L3 chunked, sha-identical, vault empty after purge)
- [x] Contract tests extended (`size`, `putFile`); api 19 tests green; web wired to real endpoints (uploads lib + share page) + esbuild check
- [x] `packages/shared-types/openapi.yaml` v0.1.0-M1
- DoD: upload 100 MB → share → expiry → bytes gone (covered by test). ✅ proven 2026-10-08

## M2 — split: M2a hardening ✅ / M2b ownership ✅ / M3a Laravel port + M3b E2EE (next)
- M2a: see previous entry (passwords/views/burn/revoke, rate limits, audit, ClamAV hook, real SFTP, GDPR docs).
- [x] M2b accounts: register/login (argon2id min-12, generic 401, 5/min gate), `pwf_` Bearer tokens (sha256 at rest), per-user quota (413 fail-closed), dashboard, JSON+ZIP export, password-confirmed cascade erasure (live proof: vault empty, token dead)
- [x] tus 1.0.0 subset (creation/HEAD/PATCH-strict-offset/termination, 409 resume, quota-aware) — live curl proof
- [x] Quarantine worker `bin/quarantine.php` closes R1 (streams unscanned assets via readRange, deletes shares+bytes on hit; skips cleanly w/o daemon; EICAR CI job non-blocking)
- [x] ADR-0004 (domain-before-framework), threat-model A8/R1-closed/R4-retired, Löschkonzept account cascade, openapi 0.3.0-M2b
- [ ] M3a: Laravel 12/13 port (spec = this codebase: Eloquent mirrors schema, controllers wrap UploadService, Sanctum/OIDC, Horizon purge+quarantine, Redis throttle)
- [ ] M3b: browser AES-GCM E2EE (key in #fragment), thumbnails, abuse-report flow

## M3 — E2EE + polish
- Browser AES-GCM E2EE (key in `#fragment`), server-blind test.
- Thumbnails, OIDC login, abuse-report flow, public docs site.
- DoD: E2EE share readable only with fragment; server DB leak reveals nothing.

## M4 — private.wf beta (DE)
- Terraform R2 + DE vault + DNS, backups, monitoring, status page.
- Closed beta (ternis users), then public OSS 1.0 + Docker images `ghcr.io/ternis-dev/private-media-upload-*`.

## Effort note
Solo/small-team: M0–M1 ≈ 3–5 days scaffolding + 2 wks slices if Laravel-first and no billing. Biggest risk: L2 SFTP throughput — load-test early (k6).
