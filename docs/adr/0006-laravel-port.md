# ADR 0006 — Laravel port: thin HTTP over the domain (M3a)

- Status: accepted (2026-10-08)
- Context: ADR-0001/0004 promised a Laravel port with the domain as spec.
  The port must not regress the live-proven behaviors (54 domain + 14 HTTP proofs).

## Decisions
1. **Thin controllers, zero Eloquent.** All behavior stays in
   `packages/api-domain` (`UploadService`, `Store`); 8 controllers only
   translate HTTP↔domain. One data path = fewer divergence bugs. Eloquent may
   arrive with complex relational needs; not before.
2. **No Sanctum.** Our `pwf_` Bearer format (sha256 at rest, per-token names,
   last_used) is kept byte-compatible via `OptionalBearer`/`RequireBearer`
   middleware — no client migration, no behavior change. OIDC still planned.
3. **No Horizon/Redis.** Workers run as Artisan commands on the scheduler
   (`pwf:purge` daily, `pwf:quarantine` hourly); rate limits stay SQLite
   fixed-window. Redis/Horizon when multi-node actually needs it (M4).
4. **Contract frozen:** same paths/statuses/payloads (openapi 0.4.0), no `/api`
   prefix, no session/CSRF middleware on contract routes.
5. **pgsql via domain DSN** (`DB_DSN`/`DB_USER`/`DB_PASS`), proven by
   `PgsqlCompatTest` (CI service) + a Laravel migration mirroring the schema.
6. **Env precedence fixed (real bug found by the port):** `Env::get` reads
   getenv-first so Dotenv files never shadow real env/putenv overrides.
   Found because Laravel loads `.env` into `$_SERVER`, which masked test
   `putenv()` isolation.
7. **StreamedResponse finalize semantics:** burn/max-views deletion runs inside
   the stream closure (after bytes). Tests must consume the stream
   (`assertStreamedContent`) to trigger it — same as production `send()`.
8. **Binary appends require a binary Content-Type:** urlencoded bodies make
   Symfony parse garbage (max_input_vars warnings). Web client sends
   `application/octet-stream`; tus sends `application/offset+octet-stream`
   (enforced, 415 otherwise).

## Consequences
- `apps/api` = Laravel 12 app; lean router retired (history preserved in git).
- Feature suite (14 tests) re-proves every M0–M3 behavior over HTTP.
- `Store::open` ordering bug (PDO-before-mkdir) fixed during the port.
