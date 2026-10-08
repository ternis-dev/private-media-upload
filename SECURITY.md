# Security Policy

## Supported versions
`main` (pre-1.0 beta) — security fixes land on `main` and latest `api-v0.*` / `web-v0.*` tags.

## Reporting a vulnerability
- Email: **security@ternis.dev** (PGP on request). Include tier affected (L1/L2/L3), repro steps, impact.
- Response target: acknowledge < 48h (DE business days), fix + advisory < 14 days for critical.
- Please do not test against `private.wf` production without permission; use local `docker compose` stack.

## Scope of interest
Link-guessing, auth bypass, IDOR on shares, S3/SFTP credential leak, E2EE bypass, purge failures, XSS on share pages, rate-limit bypass.

## Disclosure
Coordinated disclosure. We credit reporters (opt-in) in release notes and `docs/adr/`.
