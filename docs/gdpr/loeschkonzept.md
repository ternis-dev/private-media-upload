# Löschkonzept (deletion concept) — private.wf (M2a)

_Art. 17 DSGVO / Datenminimierung (Art. 5). German entity; data residency: L2/L3 DE-only, L1 global-edge (labeled)._

## What is stored per share
| Data | Where | Deleted when |
|---|---|---|
| File bytes + thumbnails (M3) | tier driver (R2 / SFTP vault / sovereign disk) | revoke: immediately · expiry: nightly purge (<24h) · burn/max-views: after final read |
| Metadata row (filename, mime, size, sha256, expiry, view counts) | SQLite now → pgsql (M2b) | with the share (purge sweeps rows + bytes together) |
| Password | argon2id hash only, never plaintext | with the share |
| Access log (hashed IP/UA via HMAC-SHA256 + salt, result, timestamp) | `access_log` | reversible? No — hashes with rotating salt; salt rotation renders old entries unlinkable. Retention 180d, then delete. |
| Rate-limit counters (IP-keyed) | `ratelimits` | sliding windows (≤1h) auto-expire |

## SLAs
- Expired share → bytes + rows gone at next nightly purge, **<24h** (`bin/purge.php` via cron; M4: monitored with alert).
- Revoke (`DELETE /v1/shares/:id`) → link dead + bytes deleted **immediately**; rows swept by purge.
- Burn / max-views → bytes + rows deleted **on final read**.
- Backups: no backups in M2a dev; M4 runbook defines backup encryption + retention (backups must honor erasure: restore-then-purge procedure).

## Betroffenenrechte (pre-account stage, capability-based)
- **Auskunft/Export:** `GET /v1/shares/:id/export` (id + password) returns metadata + access log + retention note.
- **Löschung:** `DELETE /v1/shares/:id` (id + password) — immediate byte deletion.
- With accounts (M2b): per-user export (ZIP + JSON manifest) + account deletion cascading to all shares.

## TOMs (technical-organisational measures, current)
TLS in transit · SSE/LUKS at rest (infra-dependent, runbook M4) · argon2id passwords · short-lived signed URLs · append-only audit · least-privilege DB file perms (0600 var/, 0700 vault) · no third-country transfer for L2/L3.
