# UX parity notes

The Inertia.js + React + TypeScript + Vite + Tailwind CSS + shadcn/ui stack has two pages.

- `GET /` is the health smoke page. It proves the Inertia response, the Vite client entry, and that the SSR bundle compiles.
- `GET /login` is a P0 sign-in screen (`Auth/Login`). It posts login and password to the session action. Lost password and register links, when the settings allow them, are full page loads to Blade forms. `GET /login?view=blade` still renders the Blade form at `resources/views/auth/login.blade.php`. Register, lost password, activation, and the password form are Blade. They are not Redmine screens.

This stack is not UI-ready.
This stack is not Redmine UX parity.
This stack is not production-ready.
This stack is not a 0.1 release.

The sign-in screen uses the scaffold tokens (Card, Button, Input, Label). It does not reproduce a Redmine login view, stylesheet, or workflow. A green feature test is Laramine behavior. It is not a Redmine comparison.

No row in [parity-checklist.md](parity-checklist.md) becomes **VERIFIED** because this page exists. The users and authentication behavior comparison is separate ([users-auth-spec.md](users-auth-spec.md)) and does not make this screen a Redmine login view. Journals, queries, and UX stay **NOT VERIFIED**.

Do not copy Redmine CSS or views into this tree.
