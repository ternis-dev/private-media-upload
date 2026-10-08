# 03 — Monorepo Layout

```
private-media-upload/
  .plans/               # this folder (vision → roadmap)
  apps/
    api/                # Laravel 12 (php 8.3, composer)
      app/Models/… Http/Controllers/V1/… Jobs/… Services/Storage/
      config/filesystems.php  # s3:r2, sftp:vault-de, local:sovereign
      database/migrations/
      tests/Contract/StorageDriverContractTest.php
      composer.json
    web/                # Next.js 15 (pnpm, TS)
      app/s/[id]/page.tsx  upload/page.tsx
      lib/e2ee.ts  lib/uploads.ts
      package.json
  packages/
    storage-drivers/    # framework-agnostic PHP interfaces + shared logic (if reused)
    shared-types/       # OpenAPI yaml → zod + php DTO generator (single source)
  infra/
    docker/
      compose.yml       # api, web, pgsql, redis, minio (R2-fake), sftp, clamav, mailpit
      php.Dockerfile
    terraform/          # later: r2 bucket, hetzner box, dns private.wf
  docs/
    threat-model.md
    gdpr/loeschkonzept.md  gdpr/av-vertrag-template.md
    adr/0001-php-framework.md  adr/0002-e2ee-scheme.md
  .github/workflows/ci.yml
  composer.json (root, path repos) · package.json (turbo) · turbo.json
  .env.example
```

## Tooling
- **PHP:** composer 2, php-cs-fixer + phpstan level 8, pest/phpunit.
- **TS:** pnpm workspaces + turbo, eslint + prettier + vitest + playwright (share-page flow).
- **Contracts:** `packages/shared-types/openapi.yaml` is source of truth; CI generates TS client + PHP DTOs; breaking change = major.
- **CI (GitHub Actions):** `ci.yml` jobs: `php-lint-test` (matrix php 8.3/8.4, drivers vs MinIO/SFTP containers), `web-lint-test`, `contract`, `docker-build`.
- **Versioning:** independent per app (`apps/api:v0.x`, `apps/web:v0.x`); tags `api-v0.1.0`. Changesets for web.

## Local dev (target)
```bash
cp .env.example .env
docker compose -f infra/docker/compose.yml up -d
composer install && pnpm install
php artisan migrate --seed   # demo user + 3 tier buckets
pnpm --filter web dev        # :3000  api :8000
```

## Next actions
- [ ] Scaffold `composer.json` root + `apps/api/composer.json` (laravel skeleton decision)
- [ ] Scaffold `pnpm-workspace.yaml` + `turbo.json` + `apps/web`
- [ ] Add `infra/docker/compose.yml` with minio/sftp/pgsql/redis/clamav
