# React + Vite

## CSRF / Laravel Sanctum deployment

Authentication uses Laravel's `web` session and CSRF protection, not bearer tokens.
`VITE_API_URL` is the API origin; leave it empty when the frontend and API
are served from the **same origin** (preferred).

For a same-site setup on subdomains of a domain you control:

- Configure Laravel `APP_URL=https://api.example.com` and
  `FRONTEND_URL=https://app.example.com`.
- Configure `SANCTUM_STATEFUL_DOMAINS=app.example.com`,
  `SESSION_DOMAIN=.example.com`, `SESSION_SECURE_COOKIE=true`, and
  `SESSION_SAME_SITE=lax`.
- Keep `config/cors.php` credential support enabled and ensure
  `FRONTEND_URL` matches the frontend origin exactly (scheme and host).
- Ensure the server has persistent `SESSION_DRIVER=database` storage
  (migrated sessions table and a stable APP_KEY).
- Deploy the backend with refreshed config cache, rebuild the frontend,
  and test the request sequence in browser Network tools.

**Important:** A React site on `*.vercel.app` and Laravel on
`*.onrender.com` are different *sites*. A CSRF token delivered via
`/api/csrf-token` cannot overcome a browser blocking cross-site session
cookies. Even with `SESSION_SAME_SITE=none` and
`SESSION_SECURE_COOKIE=true`, third-party cookies may be blocked.
Use a common registrable domain or a properly configured same-origin
reverse proxy; do not disable CSRF protection to hide the 419 error.

Diagnostics: GET `/api/csrf-token` must set the Laravel session cookie;
POST `/api/register` must send back the same cookie, and its
`X-CSRF-TOKEN` header must match that session. If the cookie does
not return, inspect SameSite, Secure, cookie blocking, CORS, and any
change of session storage between requests. Never expose live session
cookies, CSRF tokens, or application secrets in tickets.


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
