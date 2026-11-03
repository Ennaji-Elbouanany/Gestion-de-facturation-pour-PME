# React + Vite

## API authentication (Sanctum bearer tokens)

FFactura uses **stateless bearer tokens** for its cross-origin Render deployment.
Login and registration respond with a token (12-hour expiry). The React client
keeps it in `sessionStorage` for the lifetime of the browser tab and sends
`Authorization: Bearer <token>` with every protected API request.

The backend uses `auth:sanctum` to validate tokens, including for logout,
which revokes the current token. API requests no longer depend on Laravel's
`web` session, CSRF bootstrap, `SameSite`, or third-party cookies.
Password reset invalidates existing access tokens.

Deployment checklist:
- Backend: keep a persistent database and run migrations (the
  `personal_access_tokens` table is already included in this repository).
- Frontend: set `VITE_API_URL` to the backend origin at build time.
- Backend: `FRONTEND_URL` must match the deployed frontend origin for CORS.
- Deploy the backend first; rebuild and deploy the frontend second.
- Clear old browser cookies for the API and sign in again to obtain a bearer
  token. Previously created users can sign in; no need to register again.
- Check that `GET /api/user`, `GET /api/dashboard/summary` and
  `GET /api/clients` return 200 when `Authorization` is present; a missing
  or revoked token must receive 401.
- Never paste access tokens into a support ticket, log, or screenshot.
  `sessionStorage` avoids persisting tokens after the tab is closed but
  cannot protect against XSS. Strengthen CSP and dependency hygiene.

Run `php artisan test --filter=AuthSecurityTest` from `backend`,
`npm run lint` and `npm run build` from `frontEnd` before production release.

## Render SPA routing

The frontend uses client-side routing. Configure the Render Static Site with
this rewrite so a direct visit or browser refresh on a dashboard route serves
the React entry point:

```text
Rewrite: /*
Destination: /index.html
```

Keep this as a rewrite, not a redirect. The browser URL must remain
`/dashboard`, `/clients`, `/products`, or `/invoices` so the application can
select the corresponding dashboard section.

This template provides a minimal setup to get React working in Vite with HMR and some ESLint rules.

Currently, two official plugins are available:

- [@vitejs/plugin-react](https://github.com/vitejs/vite-plugin-react/blob/main/packages/plugin-react) uses [Oxc](https://oxc.rs)
- [@vitejs/plugin-react-swc](https://github.com/vitejs/vite-plugin-react-swc/blob/main/packages/plugin-react-swc) uses [SWC](https://swc.rs/)

## React Compiler

The React Compiler is not enabled on this template because of its impact on dev & build performances. To add it, see [this documentation](https://react.dev/learn/react-compiler/installation).

## Expanding the ESLint configuration

If you are developing a production application, we recommend using TypeScript with type-aware lint rules enabled. Check out the [TS template](https://github.com/vitejs/vite/tree/main/packages/create-vite/template-react-ts) for information on how to integrate TypeScript and [`typescript-eslint`](https://typescript-eslint.io) in your project.
