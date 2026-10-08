# 05 — Open Questions (need @ternis decision)

1. **PHP framework:** Laravel 12 API (recommended: ecosystem, Flysystem, Horizon) vs Symfony API Platform (stricter, more enterprise)? → Recommendation: **Laravel**.
2. **License:** AGPL-3.0-or-later for all (recommended for hosted privacy app) vs MIT? → Recommendation: **AGPL**.
3. **L2 provider:** Hetzner Storage Box (BX, SFTP, Falkenstein) vs Hetzner VPS+volume vs Netcup? Need AV-Vertrag check.
4. **R2 jurisdiction:** Cloudflare R2 has US parent — acceptable for L1 with explicit `Global edge` label, or EU-only S3 (Hetzner Object Storage) as L1 default?
5. **E2EE scope v1:** per-file opt-in (recommended) vs enforce for L3? Affects UX + support load.
6. **Frontend:** Next.js (recommended, hiring) vs Nuxt vs Astro+HTMX (lighter)? 
7. **Billing:** fully free OSS core for now, or paid L1/L2 quotas from day one (Stripe + SEPA)?

Reply with `1: Laravel / 2: AGPL / ...` or ask for ADRs and I'll record under `docs/adr/`.
