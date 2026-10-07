# Frontend scaffold

Mount point for later Inertia pages. This is not a Redmine UI, not product chrome, and not a 0.1 release. Limits are in [ux-parity-notes.md](ux-parity-notes.md).

Founder lock (2026-09-29): Inertia.js, React, TypeScript, Vite, Tailwind CSS, and shadcn/ui. Themes are CSS variables read by Tailwind. Plugins, when they exist, are Composer packages plus an Inertia page registry. Domain behavior stays in PHP and still targets Redmine 7.0.1 semantics. UX parity is **NOT VERIFIED**.

## What is wired

| Piece | Where |
| --- | --- |
| Laravel adapter | `inertiajs/inertia-laravel` on the `web` middleware group |
| Root template | `resources/views/app.blade.php` |
| Client entry | `resources/js/app.tsx` (`pages: './pages'`) |
| Smoke page | `resources/js/pages/Health.tsx`, `GET /` via `FrontendSmokeController` (`status` = `ok`). Links to sign-in. |
| Sign-in page | `resources/js/pages/Auth/Login.tsx`, `GET /login` via `SessionController::create`. Posts login and password to the session action. Links to lost password and register are full page loads when those settings allow them. `GET /login?view=blade` still returns `resources/views/auth/login.blade.php`. Register, activation, lost password, and `GET /my/password` are Blade forms, not Inertia pages. |
| Theme tokens | `resources/css/app.css` (`:root`, `.dark`, Tailwind `@theme inline`) |
| shadcn baseline | `components.json`. Pages use Card, Badge, Button, Input, and Label. These are scaffold controls, not a product shell. |

`@inertiajs/vite` turns `pages: './pages'` into a Vite glob of `resources/js/pages/**/*.tsx` (and `.jsx`). On `vite build --ssr` it wraps the same entry so the bundle can render that page on the server. `npm run dev` exposes the dev SSR endpoint on the Vite server.

Shared Inertia props do not include a signed-in user. The sign-in page reads validation errors from the shared `errors` bag. It uses the existing session guard. It does not add a second auth stack.

## Theme tokens

`resources/css/app.css` is the token hook: shadcn’s neutral CSS variables, mapped into Tailwind color names (`bg-background`, `text-muted-foreground`, and the rest of that set). `.dark` overrides the same names. There is no Redmine stylesheet and no application shell.

## Node SSR

The package default enables SSR when `INERTIA_SSR_ENABLED` is unset. PHPUnit forces `INERTIA_SSR_ENABLED=false` in `phpunit.xml`, so the suite does not call Node.

`npm run build` writes the client bundle under `public/build` and the SSR bundle under `bootstrap/ssr` (gitignored). Start the production SSR process with:

```bash
php artisan inertia:start-ssr
```

If `bootstrap/ssr` exists and that process is not running, the first HTML response falls back to the client payload after `INERTIA_SSR_TIMEOUT` (see `.env.example`).

## Checks

Node 22. The PHP gates do not need Node: the feature test calls `withoutVite()`.

```bash
npm ci
npm run typecheck
npm run build
```

GitHub Actions job `frontend` runs those three commands. Pint, PHPStan level 8, and PHPUnit on MySQL 8 are unchanged.

## Composer packages and Inertia pages

Founder lock (2026-09-29): a plugin is a Composer package plus an Inertia page registry. This repository does not specify that registry. Do not add a second plugin system.

TODO: when the registry is specified, a Composer package should register Inertia page component names through it. Until then, pages resolve only from `resources/js/pages` via the glob in `resources/js/app.tsx`. A package cannot register a page yet.

## Deferred

- Issues, projects, and the rest of a Redmine-like UI.
- The page registry itself.
- Parity. Nothing in this file is **VERIFIED**. The sign-in page is a P0 screen on the Phase 1 session action. It is not UI-ready, not Redmine UX parity, and not a 0.1 release. See [ux-parity-notes.md](ux-parity-notes.md).
