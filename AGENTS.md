# Agent notes

Start here, then read the doc that matches the change. Laramine is a clean-room MIT Laravel core aimed at Redmine 7.0.1 semantics. The implementation is the code, the domain docs, and the tests. It is early work. [QUALITY.md](QUALITY.md) still has an open v1 checklist. The P0 layout, users and authentication, identity, projects nested-set, workflows, custom fields, queries, and journals rows in [docs/parity-checklist.md](docs/parity-checklist.md) are **VERIFIED** by the tests named there. Every other row is **NOT VERIFIED**.

## Read these

| Topic | Path |
| --- | --- |
| How to contribute, local checks, CI, clean-room rule | [CONTRIBUTING.md](CONTRIBUTING.md) |
| Vulnerability reports, supported lines | [SECURITY.md](SECURITY.md) |
| Quality bar (do not weaken it) | [QUALITY.md](QUALITY.md) |
| Projects, membership, issue workflow | [docs/domain.md](docs/domain.md) |
| Custom fields | [docs/custom-fields.md](docs/custom-fields.md) |
| Issue queries and operators | [docs/queries.md](docs/queries.md) |
| P0 schema pin | [docs/schema-inventory.md](docs/schema-inventory.md) |
| Community KPI definitions (measurement only) | [docs/community-metrics.md](docs/community-metrics.md) |
| Users and authentication (Phase 2 account gates; later phases deferred) | [docs/users-auth-spec.md](docs/users-auth-spec.md) |
| Inertia scaffold (not a product UI) | [docs/frontend.md](docs/frontend.md), [docs/ux-parity-notes.md](docs/ux-parity-notes.md) |

## Agent kit

| Tool | Path |
| --- | --- |
| Cursor project rules | `.cursor/rules/laramine.mdc` |
| Domain continuation skill | `.cursor/skills/continue-domain-work/SKILL.md` |
| GitHub Copilot | `.github/copilot-instructions.md` |
| Claude Code | [CLAUDE.md](CLAUDE.md) (points back here) |

Use the skill when continuing issues, saved queries, or custom fields.

## Quality bar

CI on every pull request (`.github/workflows/ci.yml`, PHP 8.3, MySQL 8.0):

```bash
composer lint   # Pint --test
composer stan   # Larastan / PHPStan level 8, no baseline
composer test   # PHPUnit Unit + Feature + Parity
```

SQLite is a local migrate smoke only. PHPUnit is forced to MySQL in `phpunit.xml`. Do not retarget CI at SQLite, lower PHPStan, or add a silent baseline.

## Status you must not upgrade in prose

- The P0 table and column layout row is **VERIFIED** by `tests/Parity/SchemaLayoutParityTest.php` against `docs/sources/redmine-7.0.1-schema.rb`. The users and authentication row is **VERIFIED** by `tests/Parity/UsersAuthParityTest.php`. The identity row is **VERIFIED** by `tests/Parity/IdentityAclParityTest.php`. The projects and issue nested sets row is **VERIFIED** by `tests/Parity/ProjectNestedSetParityTest.php`. The workflows row is **VERIFIED** by `tests/Parity/WorkflowParityTest.php`. The custom fields row is **VERIFIED** by `tests/Parity/CustomFieldParityTest.php`. The queries row is **VERIFIED** by `tests/Parity/IssueQueryParityTest.php`. The journals and private notes row is **VERIFIED** by `tests/Parity/JournalParityTest.php`. Each of those tests loads `tests/Parity/fixtures/redmine-7.0.1/`. Every other P0 area is **NOT VERIFIED**. Passing the harness, or unit or feature tests, does not flip a checklist row.
- The HTTP API is not implemented beyond the Inertia health smoke page at `GET /`, the Inertia sign-in page at `GET /login` (Blade form at `?view=blade`) on the Phase 1 session action, and three custom-field routes: attachment download, attachment delete, and link URL resolution (`routes/web.php`). Those routes use the session user when one is present and do not sign anyone in. Those pages are not UI-ready, not Redmine UX parity, and not a 0.1 release (`docs/ux-parity-notes.md`). The custom fields checklist row is **VERIFIED** only by `tests/Parity/CustomFieldParityTest.php`.
- Journal rows are written on issue update (tracked attribute diffs, custom-value diffs, notes, private notes), on relation add, and by `JournalNoteService` for quote, edit, and delete of a note. Query code reads journals. Visible journals and rendered `attr`, `attachment`, `relation`, and `cf` detail lines are compared on the journals row by `tests/Parity/JournalParityTest.php`. The gate is [docs/journals-parity-gate.md](docs/journals-parity-gate.md). HTTP download, thumbnail bytes, and SCM history stay open there. Custom-field diffs are compared on the custom fields row. This is not a 0.1 tag.
- Projects, membership, workflow, and issues have an MVP smoke gate in [docs/acl-workflow-parity-gate.md](docs/acl-workflow-parity-gate.md). A core checklist row marked **PASS** there is Laramine smoke evidence from `tests/Feature/CoreChecklistSmokeTest.php`. That **PASS** is not the parity **VERIFIED** mark. The identity, nested-set, and workflows rows are **VERIFIED** only by the parity tests named in the checklist. Neither mark authorizes a 0.1 tag.
- Users and authentication are locked in [docs/users-auth-spec.md](docs/users-auth-spec.md) (2026-10-07). Phase 2 adds registration, `must_change_passwd`, and `recovery` / `register` tokens on the session guard. Do not add LDAP, two-factor, OAuth, API tokens, or account administration unless a later slice asks for them. The compared row is not a 0.1 tag. `users_visibility` is compared on the identity row. The user directory stays deferred.
- Wiki, SCM, forums, news, settings behavior, and webhooks are outside the migrated P0 slice.
- Do not describe the tree as production-ready.

## Clean-room

Behavior and schema notes may follow Redmine 7.0.1 semantics. Do not copy GPLv2 Ruby (or other upstream source) into the tree. See [CONTRIBUTING.md](CONTRIBUTING.md).
