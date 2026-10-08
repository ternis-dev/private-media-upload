# 04 — Roadmap (M0 → M4)

## M0 — Repo bootstrap (this week) — DoD
- [x] `git init`, README, LICENSE (AGPL-3.0-or-later), `.plans/00–05`
- [ ] `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `.env.example`
- [ ] `infra/docker/compose.yml` (pgsql, redis, minio, sftp, mailpit) boots green
- [ ] CI skeleton (`ci.yml` lint-only) green on `main`
- DoD: fresh clone → `docker compose up` → hello pages for api+web.

## M1 — L1 + L3 slices (2–3 wks)
- L1 direct-to-R2 (MinIO in dev) init/complete + share link + expiry purge.
- L3 local-disk tus upload + stream download + `DE-only` badge.
- Contract tests for `R2S3Driver` + `LocalSovereignDriver`.
- DoD: upload 100 MB → share → expiry → bytes gone (covered by test).

## M2 — L2 vault + hardening (2–3 wks)
- SFTP driver (Hetzner Storage Box compat) + tus proxy + LUKS notes.
- Password shares, max-views/burn, rate limits, ClamAV, audit log.
- GDPR export/delete + Löschkonzept doc.
- DoD: pentest checklist pass (guess-rate, headers, purge <24h).

## M3 — E2EE + polish
- Browser AES-GCM E2EE (key in `#fragment`), server-blind test.
- Thumbnails, OIDC login, abuse-report flow, public docs site.
- DoD: E2EE share readable only with fragment; server DB leak reveals nothing.

## M4 — private.wf beta (DE)
- Terraform R2 + DE vault + DNS, backups, monitoring, status page.
- Closed beta (ternis users), then public OSS 1.0 + Docker images `ghcr.io/ternis-dev/private-media-upload-*`.

## Effort note
Solo/small-team: M0–M1 ≈ 3–5 days scaffolding + 2 wks slices if Laravel-first and no billing. Biggest risk: L2 SFTP throughput — load-test early (k6).
