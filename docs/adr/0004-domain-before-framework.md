# ADR 0004 — M2b sequencing: domain before framework (accounts/tus/quarantine now, Laravel M3a)

- Status: accepted (2026-10-08)
- Context: ADR-0001 chose Laravel 12/13 for `apps/api`. M2b promised the port
  plus SFTP/auth/tus. Doing the Big Rewrite mid-stream would stall shippable
  value and risk the 50+ green, live-proven tests.

## Decisions
1. **Ship framework-independent domain first:** token auth + ownership + quotas
   (this ADR), tus 1.0.0 subset, quarantine worker, per-user GDPR ZIP/cascade.
   All fully specified by tests + openapi 0.3.0 — the Laravel port now has a spec.
2. **Laravel migration → M3a, mechanical:** Eloquent models mirror the SQLite
   schema 1:1 (users/tokens/uploads/assets/shares/access_log/ratelimits);
   controllers wrap `UploadService` (kept in `packages/`); Sanctum replaces the
   token table; Horizon schedules `purge` + `quarantine`; Redis throttle replaces
   SQLite windows. Lean router stays until the port passes the same live proofs.
3. **Auth model (lean, pre-OIDC):** `pwf_` bearer tokens (32 B CSPRNG, sha256 at
   rest), argon2id account passwords (min 12), generic login failures, 5/min/IP
   login gate. OIDC/passkeys with Laravel (M3a).
4. **tus subset, not full protocol:** Creation (Upload-Length + Metadata required),
   HEAD offset, PATCH strict-offset, Termination. No checksum extension yet
   (sha256 verified at complete()); concatenation extension deferred.

## Consequences
- Roadmap: M2b (done here) → M3a Laravel port → M3b E2EE.
- Threat model: R1 closed by quarantine worker; R4 (capability-only auth) retired
  for owned shares; new residual R6 (direct-R2 scan latency: worker runs hourly).
