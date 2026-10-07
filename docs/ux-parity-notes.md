# UX parity notes

The Inertia.js + React + TypeScript + Vite + Tailwind CSS + shadcn/ui scaffold is a boot path for later pages.

- It is not UI-ready.
- It is not Redmine UX parity.
- It is not production-ready.
- It is not a 0.1 release.

The only page is the health smoke page at `/`. It proves the Inertia response, the Vite client entry, and that the SSR bundle compiles. It does not reproduce Redmine screens, styles, or workflows.

No row in [parity-checklist.md](parity-checklist.md) becomes **VERIFIED** because this scaffold exists. Journals, users, and queries stay **NOT VERIFIED**. Phase 1 session sign-in stays the Blade form already on main ([users-auth-spec.md](users-auth-spec.md)). This slice does not add a login screen.

Do not copy Redmine CSS or views into this tree.
