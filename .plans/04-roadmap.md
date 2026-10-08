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

## M2 — split: M2a hardening ✅ DONE / M2b Laravel + async workers (next)
- [x] Real SFTP L2 driver (phpseclib, env-gated live test in CI; emulation fallback)
- [x] Share security: argon2id passwords, max-views (atomic), burn-after-read (live 200→404 proof), revoke-now + purge sweep
- [x] Rate limits (SQLite fixed-window, live 429 proof) + PII-minimized audit log + capability GDPR export/delete
- [x] ClamAV INSTREAM hook on staged path (skippable dev default; direct-to-R2 gap R1 tracked)
- [x] `docs/threat-model.md`, `docs/gdpr/loeschkonzept.md`, `docs/adr/0003-share-security.md`, openapi 0.2.0-M2a
- [ ] M2b: Laravel 12/13 migration (port Store/UploadService/Drivers → pgsql + Horizon), S3-event AV quarantine worker (closes R1), Redis throttle, accounts + per-user GDPR cascade, tus proxy
- DoD: pentest checklist pass (guess-rate, headers, purge <24h). — partial: automated proofs green (429/401/410/200→404, purge<24h by cron); full checklist with auth in M2b

## M3 — E2EE + polish
- Browser AES-GCM E2EE (key in `#fragment`), server-blind test.
- Thumbnails, OIDC login, abuse-report flow, public docs site.
- DoD: E2EE share readable only with fragment; server DB leak reveals nothing.

## M4 — private.wf beta (DE)
- Terraform R2 + DE vault + DNS, backups, monitoring, status page.
- Closed beta (ternis users), then public OSS 1.0 + Docker images `ghcr.io/ternis-dev/private-media-upload-*`.

## Effort note
Solo/small-team: M0–M1 ≈ 3–5 days scaffolding + 2 wks slices if Laravel-first and no billing. Biggest risk: L2 SFTP throughput — load-test early (k6).
