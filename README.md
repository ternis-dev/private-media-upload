# private.wf — Private Media Upload & Sharing Platform

**Monorepo** · by [ternis.dev](https://ternis.dev) & [ternis.org](https://ternis.org) · **Fully open-sourced (AGPL-3.0-or-later).**

Germany-based, GDPR-first media upload & sharing with **explicit privacy tiers**:

| Tier | Backend | Speed / Locations | Privacy | Use case |
|------|---------|-------------------|---------|----------|
| **L1 — Edge Object Storage** | S3-compatible (Cloudflare R2, Hetzner Object Storage, MinIO) | Fast up/down, multi-region / CDN | Baseline: provider holds ciphertext, standard at-rest encryption | Public / large files, viral shares |
| **L2 — Provider Vault (DE)** | FTP/SFTP on provider server, single location (DE) | Same upload as object storage, 1 location, no CDN copy | Medium: EU-only custody, no replication | Business / personal, GDPR-sensitive |
| **L3 — Sovereign Node** | Customer / own server (local disk) | Slower, ONE location only, no third party | Highest: zero third-party custody, you hold keys+disks | Family, legal, medical, journalists |

Orthogonal to placement: **E2EE (optional per-upload) + expiring links + password + max-views + audit log.**

> Status: `00-planning`. See [.plans/](./.plans/README.md). No production code yet.

## Monorepo layout

```
apps/
  api/        # PHP 8.3+ backend (proposed: Laravel 12 API) — auth, uploads, shares, jobs
  web/        # TypeScript frontend (proposed: Next.js) — upload UI, share pages
packages/
  storage-drivers/  # Flysystem abstraction: s3/r2, sftp, local-sovereign
  shared-types/     # OpenAPI / Zod / DTOs shared between api+web
infra/
  docker/     # local dev: php, db, minio (fake-R2), sftp, mailpit
  terraform/  # R2 bucket, Hetzner volume, DNS private.wf (later)
docs/
  threat-model.md / gdpr/ / adr/
.plans/       # vision, tiers, architecture, roadmap (start here)
```

## Quickstart (planned)

```bash
cp .env.example .env
docker compose up -d
composer install && pnpm install
php artisan migrate --seed
php artisan serve & pnpm --filter web dev
```

## Principles

1. **Privacy is explicit, not marketing.** Every upload shows where bytes live, how long, who can read.
2. **Self-hostable in 10 min.** `docker compose up` gives you L3 sovereign mode with zero cloud.
3. **S3 is an adapter, not the core.** Core = `StorageDriverInterface`; R2/SFTP/local are plugins.
4. **GDPR by default.** DE/EU data residency, AV-Verträge docs, Löschkonzept, Data minimization, audit logs.
5. **Open source, AGPL.** Host it yourself; improvements flow back.

## Contributing

See [CONTRIBUTING.md](./CONTRIBUTING.md) · [SECURITY.md](./SECURITY.md) · [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md).

## License

AGPL-3.0-or-later — see [LICENSE](./LICENSE).
