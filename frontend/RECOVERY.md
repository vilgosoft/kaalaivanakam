# Frontend recovery (Kaalaivanakam)

The **React TypeScript source** under `src/` was lost. This folder is wired to run the **last good production bundle** copied from `deployment/hostinger-upload/public_html/`:

- `public/assets/index-DdmIdlbA.js`
- `public/assets/index-VXMDN686.css`

Branding matches **Kaalaivanakam** (spelling fix) and matches what you deploy to Hostinger.

## Local dev

1. **API:** from `frontend/`, run `npm run dev:api` (PHP on `:8888` with router), or your stack.
2. **UI:** `npm run dev` — Vite proxies `/v1` → `http://127.0.0.1:8888`.

## Refresh the bundle after backend changes

From repo root:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/package-hostinger-upload.ps1
```

Then refresh the recovered UI in one step:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/sync-frontend-bundle-to-public.ps1
```

(Or manually copy `assets/*`, `favicon.svg`, `icons.svg`, and `index.html` from `deployment/hostinger-upload/public_html/` into `frontend/public/` and `frontend/`.)

## Long term

Initialize git and commit, or restore `src/` from an old backup / another machine if you still have it.
