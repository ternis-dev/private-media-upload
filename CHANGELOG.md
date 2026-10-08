# Changelog — private.wf

## Unreleased (M4 beta)
- Thumbnails (GD images, ffmpeg first-frame; E2EE never thumbnailed; purge-safe)
- Abuse reports (`POST /v1/shares/:id/report` + `pwf:reports` review)
- Deep health (`GET /v1/health`: db, per-tier probes, clamav) + security headers
  (nosniff, SAMEORIGIN, no-referrer, Permissions-Policy, conditional HSTS)
- `?thumb=1` previews (gated, never consume views, JPEG content type)
- Prod stack: api/worker/nginx/web compose, Dockerfiles, Terraform (R2+DNS+TLS,
  validated), `pwf:backup` (VACUUM INTO + manifest), runbook, release workflow
- pgsql proven live (compat suite vs postgres:16)

## M3b — E2EE
- AES-256-GCM PWF1 containers, key in `#k=` fragment, server blind
  (opaque store, no sniff/scan, quarantine-skip `scanned=2`); web encrypt/decrypt

## M3a — Laravel port
- Laravel 12 thin HTTP over `packages/api-domain` (no Eloquent, custom Bearer,
  scheduler workers); 14 feature tests; Env precedence + PDO-mkdir bugs fixed

## M2b — Ownership
- Accounts (argon2id, `pwf_` tokens, quotas), tus 1.0.0 subset, quarantine
  worker (closed R1), per-user ZIP export + cascade erasure, `/me` web page

## M2a — Hardening
- Share passwords, max-views (atomic), burn-after-read, revoke-now, rate limits,
  audit log, ClamAV hook, real SFTP L2, Löschkonzept, threat model

## M1 — Byte flows
- Chunked uploads all tiers, SigV4 L1 presigned, Range streaming, purge CLI,
  SQLite metadata, wired web, openapi 0.1.0

## M0 — Bootstrap
- Monorepo, `.plans/`, tiers L1/L2/L3, storage-drivers contracts, lean API+web
