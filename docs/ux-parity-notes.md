# UX parity notes

The Inertia.js + React + TypeScript + Vite + Tailwind CSS + shadcn/ui stack has two pages.

- `GET /` is the health smoke page. It proves the Inertia response, the Vite client entry, and that the SSR bundle compiles.
- `GET /login` is a P0 sign-in screen (`Auth/Login`). It posts login and password to the Phase 1 session action. `GET /login?view=blade` still renders the Blade form at `resources/views/auth/login.blade.php`.

This stack is not UI-ready.
This stack is not Redmine UX parity.
This stack is not production-ready.
This stack is not a 0.1 release.

The sign-in screen uses the scaffold tokens (Card, Button, Input, Label). It does not reproduce a Redmine login view, stylesheet, or workflow. A green feature test is Laramine behavior. It is not a Redmine comparison.

No row in [parity-checklist.md](parity-checklist.md) becomes **VERIFIED** because this page exists. Users and authentication stay **NOT VERIFIED** ([users-auth-spec.md](users-auth-spec.md)). Journals and queries stay **NOT VERIFIED**.

Do not copy Redmine CSS or views into this tree.
