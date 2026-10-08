# ADR 0007 — M4 beta readiness (thumbnails, reports, prod stack)

- Status: accepted (2026-10-08)
- Context: feature-complete for closed beta; remaining work is operations.

## Decisions
1. **Thumbnails server-side, never for E2EE** (GD images ≤50 MB, ffmpeg
   first-frame; JPEG 512px). Rationale: previews drive adoption; ciphertext
   can't be thumbnailed by definition (R7). Purge deletes thumbs with bytes.
2. **`?thumb=1` previews never consume views** (preview ≠ read), but pass the
   same password/gates and serve `image/jpeg`. Direct blob-URL thumb reads
   behave identically.
3. **Abuse reports unauthenticated + rate-limited** (10/h), reviewed via
   `pwf:reports` (server access = auth). No automated takedown in v1 — human
   review, then existing revoke path.
4. **Deep health always-200** with `ok` flag (dashboards over LB semantics);
   per-tier live probes (put/read/delete probe objects); clamav `skipped`
   when unconfigured.
5. **Infra as code where provable:** Terraform validated locally
   (`validate`+`fmt`, provider v5 schema-checked); Docker images buildable in
   CI (registry blocked in dev, so `config -q` locally + build job in CI).
6. **Backups erasure-aware:** `pwf:backup` snapshots + manifest; documented
   restore-then-purge so expiry is never resurrected.
7. **Deferred (honest):** OIDC (needs provider creds), Horizon/Redis
   (single-node beta doesn't need them), E2EE streaming decrypt >1 GB,
   automated CSAM hashing (non-goal v1).
