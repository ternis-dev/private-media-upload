# apps/api — private.wf API on Laravel 12 (M3a)

Thin HTTP layer over the framework-free domain (`packages/api-domain`).
Same contract as the lean router: `packages/shared-types/openapi.yaml`.
No Eloquent models — **one data path** through `PrivateWf\Api\Store`
(sqlite file by default, pgsql via `DB_DSN`).

## Run
```bash
cp .env.example .env && php artisan key:generate
composer install
php artisan serve            # :8000 (APP_URL)
```

## Test
```bash
php artisan test              # Feature suite (HTTP parity, :memory: sqlite)
php artisan migrate --force   # schema proof (pgsql in prod/CI)
```

## Layout
- `routes/api.php` — all contract routes, no `/api` prefix, per-route `pwf.*` middleware
- `app/Http/Controllers/` — 8 thin controllers delegating to `UploadService`
- `app/Http/Middleware/` — `ApiRateLimit` (domain limiter), `OptionalBearer`/`RequireBearer`
- `app/Support/Api.php` — password/audit/authorize helpers; `App\Exceptions\ApiError` → JSON
- `app/Console/Commands/` — `pwf:purge` (daily), `pwf:quarantine` (hourly) via `routes/console.php`
- `database/migrations/` — pgsql-ready schema mirror of `Store::migrate`

Deliberate non-goals (see `docs/adr/0006-laravel-port.md`): no Sanctum
(custom `pwf_` Bearer kept), no Horizon/Redis (scheduler + SQLite windows),
no Eloquent (domain Store is the single data path).
