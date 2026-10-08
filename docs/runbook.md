# Runbook — private.wf beta ops (M4)

## Deploy (prod compose)
```bash
cp apps/api/.env.example apps/api/.env   # then fill secrets (below)
php artisan key:generate --show          # → APP_KEY
docker compose -f infra/docker/compose.prod.yml up -d --build
docker compose -f infra/docker/compose.prod.yml exec api php artisan migrate --force
curl -s https://private.wf/v1/health     # {"ok":true,...} or degraded map
```
Required secrets: `APP_KEY`, `DB_PASSWORD`, `HMAC_SECRET` (+ `S3_L1_*` for L1-real,
`SFTP_L2_*` for L2-real, `AUDIT_SALT` recommended). Never commit `.env`.

## Scheduler (in-stack)
`worker` runs `schedule:work`: `pwf:purge` daily, `pwf:quarantine` hourly.
Verify: `docker compose exec worker php artisan schedule:list`.
Without the worker, expiry still works only if `pwf:purge` runs via system cron
(Löschkonzept <24h SLA depends on one of them running).

## Backups (erasure-aware)
- Metadata: `pwf:backup` (sqlite `VACUUM INTO` snapshot + manifest) or `pg_dump`
  for pgsql. Snapshots land in `VAR_DIR/backups/` (mount off-site).
- Bytes: vault dir rsync (L3), Storage-Box snapshots (L2), R2 replication (L1).
- **Restore-then-purge (mandatory):** after any restore, immediately run
  `pwf:purge` — backups may contain shares that expired after the snapshot;
  restoring must not resurrect them.

## Key rotation
- `HMAC_SECRET`/`AUDIT_SALT`: rotate anytime — only short-lived signed URLs
  invalidate (≤15 min); password hashes (argon2id) are unaffected.
- `APP_KEY`: Laravel sessions/cookies only (API is stateless); rotate freely.

## Abuse incidents
1. `php artisan pwf:reports` → review queue.
2. Illegal content: `DELETE /v1/shares/:id` (revoke-now, bytes die immediately).
3. Mark: `php artisan pwf:reports --action=<id>:actioned`.
4. Serious cases (CSAM): preserve access_log excerpt for authorities, then purge
   per Löschkonzept. No PhotoDNA-style hashing in v1 (documented non-goal).

## Monitoring
- Alert on: `GET /v1/health` → `ok:false`, 5xx rate, purge worker silence >26h
  (check `storage/logs` / scheduler heartbeat), disk >80% (vault), ClamAV `down`.
- Logs: Laravel `storage/logs` (stdout in containers); access_log stays in-DB
  for GDPR export; never log share passwords or E2EE keys (keys never reach us).

## TLS/DNS
Terraform (`infra/terraform`) manages zone + R2 + strict TLS. HSTS header is
emitted by the app only when `APP_URL` is https (never on localhost http).
