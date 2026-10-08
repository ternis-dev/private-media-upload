# 00 — Vision: private.wf

## Problem
Existing share tools (Imgur, WeTransfer, Dropbox) force a trade-off: fast+convenient **or** private.
German/EU users (families, freelancers, clinics, journalists) need both — with a **visible, enforceable** privacy choice per upload, not buried in ToS.

## Product
`private.wf` — open-source, self-hostable media upload & sharing:

- Drag-drop upload (image/video/audio/pdf, ≤5 GB per file in v1) → short link `private.wf/s/:id`
- Per-upload **privacy tier picker** (L1/L2/L3, see `01-privacy-tiers.md`)
- Controls: expiry (1h–90d / never for L3), password (argon2id), max-views, burn-after-read, no-index default
- Optional **E2EE mode**: AES-256-GCM in browser, server never sees plaintext
- Audit: who viewed, when, from where (owner-visible); GDPR export/delete

## Users
1. **Private sharer** (primary): quick family/friend shares, wants L2/L3 default DE.
2. **Pro / small org**: client file exchange, needs AV-Vertrag + retention policy.
3. **Self-hoster**: runs full stack on own VPS/NAS (L3-only mode).

## Non-goals (v1)
- No social feed, no comments, no AI tagging.
- No video transcoding farm (thumbnails only via queue).
- No multi-tenant billing in core (keep OSS core clean; billing as optional plugin later).

## Success criteria
- Upload p95 < 5s for 100 MB on L1/L2 (DE broadband); L3 documents expected slower.
- Self-host install < 10 min via `docker compose up`.
- `composer test` + `pnpm test` green; S3/SFTP/local drivers covered by contract tests.

## Next actions
- [ ] Confirm product name + domain (`private.wf`) and default tier
- [ ] Decide PHP framework: Laravel 12 API (recommended) vs API Platform — see `05-open-questions.md`
- [ ] Ratify AGPL-3.0-or-later for full monorepo
