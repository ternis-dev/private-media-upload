# ADR 0003 — Share security: password / max-views / burn (M2a)

- Status: accepted (2026-10-08)
- Context: pre-account stage (no users until Laravel M2b), but shares need
  protection now: passwords, single-use links, abuse throttling, audit trail.

## Decisions
1. **Passwords:** argon2id via `password_hash()` (available, verified), stored hash only.
   Transport: `X-Share-Password` header preferred, `?password=` fallback for curl.
2. **View accounting in SQLite, atomically:** single
   `UPDATE shares SET views=views+1 … WHERE views<max_views` + rowCount check.
   Redis atomic counters come with Laravel (M2b). Concurrent-race residual documented.
3. **Consume-at-serve, not at redirect** — except L1-real (bytes never touch PHP),
   where redirect consumes with 5-min SigV4 TTL + revoke-on-spent. L1-real burn
   residual (R3) documented in threat model; strict burn → L2/L3.
4. **Burn ≡ maxViews=1**, enforced in `validateShareOptions`.
5. **Revoke = link dead + bytes deleted now**, rows swept by purge (keeps audit).
6. **GDPR pre-accounts = capability URLs:** export + delete keyed by (id, password).
   Full per-account export cascades with Laravel (M2b).
7. **Rate limits in SQLite fixed-window per IP** (no Redis yet): init 30/h,
   guess 60/min, blob 120/min, append 600/h. Redis-backed throttle with Laravel.
8. **ClamAV inline on staged path only** (`ClamAv::scanFile` INSTREAM, skippable
   when unconfigured). Direct-to-R2 gap (R1) → async quarantine worker M2b.

## Consequences
- `shares` gains password_hash/max_views/views/burn/revoked_at (+auto-migration).
- New tables: `access_log` (hashed IP/UA), `ratelimits`.
- Router grows 401/410/429 mappings; openapi → 0.2.0-M2a.
