# apps/web — private.wf frontend (Next.js 15, Tailwind v4)

Dark-first UI: tiered upload flow, share pages (preview/decrypt/report),
account dashboard. Design tokens in `app/globals.css`.

## Architecture: same-origin API proxy
The browser **never** calls the backend directly. All client code uses
`/api/*`, served by `app/api/[...path]/route.ts`, which forwards to the
Laravel API (`API_BASE`, server-side runtime env). This removes wrong-host
URLs baked at build time, CORS, and mixed-content failures — and turns
backend outages into actionable 502 JSON instead of `Failed to fetch`.

- Client: `lib/api.ts` (`apiFetch`/`apiJson`) + `lib/uploads.ts` (chunk flow)
- Crypto: `lib/e2ee.ts` (PWF1 containers, key in `#k=` fragment only)
- Contract: backend `packages/shared-types/openapi.yaml`

## Env
| Var | Where | Purpose |
|---|---|---|
| `API_BASE` | server runtime | backend origin for the proxy (compose sets it; default `http://localhost:8000`) |

No `NEXT_PUBLIC_*` API URLs — nothing backend-related is baked at build time.

## Dev
```bash
npm install
npm run dev        # :3000 (needs API on :8000, or set API_BASE)
npm test           # node --test + TS-extension resolver hook
npm run build      # production build (runs in CI too)
```
