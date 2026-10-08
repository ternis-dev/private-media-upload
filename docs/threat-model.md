# Threat model — private.wf (M2a)

Scope: lean-PHP M1/M2a stack (SQLite, no accounts yet). Updated per milestone; Laravel/auth changes will amend this file.

## Attackers
| # | Attacker | Capability | Out of scope? |
|---|---|---|---|
| A1 | Link guesser | internet, high request rate | No — 12-char base62 ids (~71 bit) + 60/min/IP share gate |
| A2 | Curious storage operator (L1/L2 hoster) | reads disks/buckets at rest | Partially — random keys, no listing; content visible unless E2EE (M3) |
| A3 | Curious server admin (us) | DB + vault access | Partially — argon2id password hashes (not plaintext), audit log; E2EE (M3) removes content |
| A4 | Malware uploader | uploads EICAR-class payloads | No — ClamAV inline scan on staged path (M2a), direct-to-R2 deferred to async worker (M2b, tracked risk R1) |
| A5 | Abuser (CSAM/extremism) | shares illegal content | Reporting flow M3; hashes (PhotoDNA-style) explicitly non-goal v1 |
| A6 | Passive network observer | taps traffic | No — TLS terminates at edge (infra); HMAC/SigV4 URLs expire (15 min / 5 min L1-real) |
| A7 | Forensic analyst (deleted data) | seizes disks post-purge | Purge deletes bytes + rows; SQLite WAL/VACUUM caveat (R2) |
| A8 | Credential stuffer | password-sprays login/register | No — login 5/min/IP + generic errors (no enumeration), argon2id, min-12 account passwords |

## Controls (implemented → test)
- Unguessable ids: `Shares::newId` (CSPRNG, 62^12) — `SharesTest`, live 404-rate proof.
- Rate limits (SQLite fixed-window, per IP): init 30/h, guess 60/min, blob 120/min, append 600/h — `RateLimitTest` + live 429 proof.
- Passwords: argon2id via `password_hash`, never logged, header-preferred transport — `ShareSecurityTest` + live 401/200 proof.
- View accounting atomic (`UPDATE … WHERE views<max_views`) — `ShareSecurityTest`; burn deletes blob+rows after serve — live 200→404 proof.
- Signed URLs: HMAC-SHA256 (L2/L3/emu) / SigV4 (L1-real), short TTL — contract tests.
- Audit: hashed IP/UA, append-only `access_log`, exportable — `ShareSecurityTest::test_export_manifest`.
- Headers: `X-Robots-Tag: noindex`, CSP on API (no content), `Content-Disposition: attachment` (no inline XSS via SVG/HTML).
- Upload validation: 5 GB cap, overflow reject, traversal reject, mime sniff recorded.

## Accepted risks
- **R1 (CLOSED M2b):** direct-to-R2 uploads skipped inline AV — now covered by the
  hourly quarantine worker (`bin/quarantine.php`: streams unscanned assets via
  readRange, scans, deletes shares+bytes on hit). Residual R6: detection latency
  ≤ worker interval; staged L2/L3 path still scans inline at complete().
- **R2 (open):** SQLite `VACUUM`/`WAL` may retain deleted bytes on disk; pgsql + `VACUUM` policy with Laravel (M2b). LUKS at rest recommended (infra runbook M4).
- **R3 (accepted):** L1-real single-use links: issued SigV4 URL valid for its 5-min TTL even after revoke. Mitigated by short TTL; strict burn → use L2/L3 proxy.
- **R4 (retired M2b for owned shares):** capability-only auth supplemented by
  accounts: owned shares manageable + erasable per-user; anonymous shares keep
  the 71-bit + rate-limit bar (documented until accounts become mandatory).
- **R5 (accepted):** `?password=` query fallback leaks into access logs/proxies — header preferred, web client uses header; query kept for curl ergonomics.
