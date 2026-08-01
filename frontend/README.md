# frontend/ — Vue 3 SPA

Vue 3 (Composition API only), Vue Router, Axios, Vite, Tailwind v4.
Mobile-first: a bottom tab bar on small screens, a sidebar on larger ones
(`src/layouts/AppShell.vue`) — see
[`../DECISIONS.md`](../DECISIONS.md) "Frontend state management" for why
there's no Pinia (plain composables instead) and the memory note on the
project's mobile-first/modern design bar.

## What's here

- `src/pages/Login.vue` — Sanctum SPA cookie auth via `src/composables/useAuth.js`.
- `src/pages/Dashboard.vue` — stat tiles, polls `/api/dashboard` every 30s.
- `src/pages/Imports.vue` — CSV upload (tap-to-browse primary, drag-and-drop
  as a desktop bonus) with upload progress and a batch history list that
  polls faster while an import is active.
- `src/lib/api.js` — Axios instance + the Sanctum CSRF-cookie helper.

## Local setup

```bash
npm install
npm run dev
```

Talks to the Laravel API at `http://127.0.0.1:8000` via the dev-server
proxy in `vite.config.js` (so cookies stay same-origin — no CORS
configuration needed in dev). In production, Nginx serves this build's
`dist/` and proxies `/api`/`/sanctum` to PHP-FPM instead — see
`../DEPLOYMENT.md`.
