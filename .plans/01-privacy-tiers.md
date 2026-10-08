# 01 — Privacy Tiers (L1 / L2 / L3)

Core differentiator. Privacy = **placement × encryption × retention × access**.

## Tier definitions

### L1 — Edge Object Storage (fast, replicated)
- **Backends:** S3-compatible: Cloudflare R2 (default), Hetzner Object Storage, AWS S3, MinIO (dev).
- **Topology:** multi-region / CDN edge cached. Fastest up/download.
- **Encryption:** TLS 1.2+ in transit; SSE-S3 at rest; optional per-file E2EE.
- **Trust:** operator (e.g. Cloudflare) stores ciphertext; subject to provider ToS + possible non-EU replication → **lowest privacy**, must be labeled as such in UI.
- **Retention:** bucket lifecycle rules (auto-purge expired shares daily).
- **Good for:** large public-ish files, memes, OSS release artifacts.

### L2 — Provider Vault DE (balanced, single-location)
- **Backends:** SFTP/SSH or FTPES to Hetzner-hosted storage box / VPS volume in **Falkenstein/Nuremberg (DE)** only.
- **Topology:** ONE location, no CDN copy. Same raw upload speed as L1 (no edge), slower global downloads — honest trade-off.
- **Encryption:** SFTP (SSH) preferred; FTP only over explicit TLS; at-rest LUKS on host + app-level envelope encryption.
- **Trust:** ternis-operated or contracted DE provider, AV-Vertrag, no third-country transfer. **Medium privacy.**
- **Retention:** nightly purge job + WORM-ish audit log (append-only views table).
- **Good for:** default for logged-in DE users; client documents, family videos.

### L3 — Sovereign Node (slowest, highest privacy)
- **Backends:** local Flysystem `local` adapter on operator's own server / NAS / on-prem. Path: `/srv/private-wf/vault`.
- **Topology:** ONE location, operator-owned hardware, no replication unless operator configures RAID/backup themselves.
- **Encryption:** LUKS + app envelope; E2EE strongly suggested in UI copy.
- **Trust:** zero third-party custody. Operator holds keys + disks. **Highest privacy.**
- **Trade-offs:** slower up/down (residential uplink), single point of failure, operator does backups.
- **Good for:** medical/legal/journalism, family archive. Self-hosters run L3-only.

## Orthogonal controls (all tiers)
| Control | Default | Notes |
|---|---|---|
| E2EE (browser AES-GCM-256, key in `#fragment`) | off, one-click on | server stores only ciphertext; no password-reset for E2EE links |
| Expiry | 7d (L1/L2), 30d (L3) | cron purges bytes + DB row (soft→hard delete 7d grace) |
| Password (argon2id) | optional | rate-limited attempts (5/min/IP + account) |
| Max views / burn-after-read | optional | counter in Redis, atomic decrement |
| robots noindex + signed URL | always | `X-Robots-Tag: noindex`, short `nanoid(12)` IDs |

## GDPR mapping (DE entity)
- **Rechtsgrundlagen:** Art. 6(1)(b) hosting + (f) abuse prevention; Auftragsverarbeitung Art. 28 for L1/L2 providers.
- **TOMs:** TLS, SSE, key rotation (90d), append-only access logs (180d), Löschkonzept (expired bytes purged <24h).
- **Datenstandort badge in UI:** `DE-only 🇩🇪` (L2/L3) vs `Global edge 🌍` (L1) — user must actively pick L1.
- **Betroffenenrechte:** export (ZIP + JSON manifest) + delete (hard purge incl. thumbnails + logs anonymized).

## Threat model (summary)
- Attacker reads R2 bucket → mitigated by random IDs + no listing + optional E2EE.
- Link brute-force → 128-bit ID space + rate limit + optional password.
- Malicious upload (malware/CSAM) → ClamAV scan in queue, magic-byte validation, max size, abuse report button. (Detail: `docs/threat-model.md`.)
- Admin curiosity → E2EE mode + audit log + least-privilege DB roles.

## Next actions
- [ ] Pick L2 hoster (Hetzner Storage Box vs VPS volume) + region pin
- [ ] Decide E2EE library (libsodium via `sodium_compat` + WebCrypto) and key-in-fragment scheme
- [ ] Draft Löschkonzept + AV-Vertrag templates (`docs/gdpr/`)
