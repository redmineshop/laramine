# Laramine — Copilot instructions

MIT Laravel project-management core, clean-room relative to Redmine. Full pointers: `AGENTS.md`. Contribution and CI rules: `CONTRIBUTING.md`. Quality bar: `QUALITY.md`. Vulnerability reports: `SECURITY.md`.

## Hard rules

- MySQL 8 is the app and test database. PHPUnit is forced there by `phpunit.xml`. SQLite is a documented local migrate smoke only.
- Before a PR: `composer lint` (Pint `--test`), `composer stan` (Larastan level 8, no baseline), `composer test` (PHPUnit Unit, Feature, and Parity). CI in `.github/workflows/ci.yml` must stay green. Do not weaken it.
- Clean-room: reimplement from Redmine 7.0.1 behavior and schema semantics. Never add GPLv2 Ruby, ERB, JS, or upstream tests to the tree. Structure dump for reference only: `docs/sources/redmine-7.0.1-schema.rb`.
- The P0 table and column layout row is **VERIFIED** by `tests/Parity/SchemaLayoutParityTest.php`. The users and authentication row is **VERIFIED** by `tests/Parity/UsersAuthParityTest.php`. The identity, projects nested-set, workflows, and custom fields rows are **VERIFIED** by `tests/Parity/IdentityAclParityTest.php`, `tests/Parity/ProjectNestedSetParityTest.php`, `tests/Parity/WorkflowParityTest.php`, and `tests/Parity/CustomFieldParityTest.php` (`docs/parity-checklist.md`). Every other row is **NOT VERIFIED**. Do not mark another row VERIFIED without a fixture-backed comparison in `tests/Parity` and an evidence path. Do not call the project production-ready.
- The HTTP API is not implemented beyond `GET /` (an Inertia health smoke page) and `GET /login` (an Inertia sign-in page on the Phase 1 session action, with the Blade form at `?view=blade`; `docs/frontend.md`, `docs/ux-parity-notes.md`: not UI-ready, not Redmine UX parity, not a 0.1 release), and two custom-field reads (attachment download and link URL resolution). Wiki, SCM, forums, news, and webhooks are out of the current slice. Issue journals are written on update and relation add. Quote, edit, and delete of a note also write; see `docs/domain.md` and `docs/journals-parity-gate.md`. Projects, membership, workflow, and issues have an MVP smoke in `docs/acl-workflow-parity-gate.md`. A **PASS** row in that gate's core checklist acceptance section is Laramine smoke, not the checklist **VERIFIED** mark. The identity, nested-set, and workflows rows are **VERIFIED** only by the parity tests named in the checklist. Neither gate is a 0.1 tag. Say so if you touch those edges.

## Where code lives

- Domain services: `app/Domain` (`Issues`, `Queries`, `CustomFields`, `Acl`, `Workflow`, `Projects`).
- Eloquent models map Redmine 7.0.1 table and column names (`created_on` / `updated_on` where the schema uses them). STI `type` values stay Redmine class names (`IssueCustomField`, `WorkflowTransition`).
- Permission names are the strings in `App\Domain\Acl\PermissionCatalog`.
- Text columns that Redmine stores as YAML are JSON on write. YAML is read compatibility only, where the domain docs already say so.
- Tests: `tests/Unit`, `tests/Feature` (often `RefreshDatabase` and `Tests\Support\DomainFixture`), `tests/Parity`.
- Docs: `docs/domain.md`, `docs/custom-fields.md`, `docs/queries.md`, `docs/journals-parity-gate.md`, `docs/acl-workflow-parity-gate.md`, `docs/users-auth-spec.md`. Update the matching doc when behavior changes. Users/auth is locked there. Phase 2 is registration, `must_change_passwd`, and `recovery` / `register` tokens. Do not add LDAP, two-factor, OAuth, API tokens, or account administration from a side task. The compared row is not a 0.1 tag.

## Domain work

For issues, queries, or custom fields, follow `.cursor/skills/continue-domain-work/SKILL.md`. Prefer extending the existing service and the documented operator or format catalog. Unknown filters and deferred formats fail closed; they do not match every row.

Imports stay at the top of the PHP file. Code comments are English. `match` on a backed enum stays exhaustive.
