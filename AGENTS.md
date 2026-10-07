# Agent notes

Start here, then read the doc that matches the change. Laramine is a clean-room MIT Laravel core aimed at Redmine 7.0.1 semantics. The implementation is the code, the domain docs, and the tests. It is early work. [QUALITY.md](QUALITY.md) still has an open v1 checklist. The P0 layout, users and authentication, identity, projects nested-set, workflows, custom fields, queries, journals, time entries and attachments, outbound mail, activity, news, documents, and files, wiki, boards and forums, calendar and Gantt, Textile and Markdown rendering, and the notification and activity rows for those modules in [docs/parity-checklist.md](docs/parity-checklist.md) are **VERIFIED** by the tests named there. Textile and Markdown rendering is **VERIFIED** by `tests/Parity/MarkupParityTest.php`. Activity for changesets stays **NOT VERIFIED**. Every other row is **NOT VERIFIED**. Calendar and Gantt are **VERIFIED** by `tests/Parity/CalendarGanttQueryParityTest.php`.

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
| Users and authentication (phases 1–10; live LDAP and OIDC stay open) | [docs/users-auth-spec.md](docs/users-auth-spec.md) |
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

- The P0 table and column layout row is **VERIFIED** by `tests/Parity/SchemaLayoutParityTest.php` against `docs/sources/redmine-7.0.1-schema.rb`. The users and authentication row is **VERIFIED** by `tests/Parity/UsersAuthParityTest.php`. The later-phase sub-rows that cite a passing comparison are **VERIFIED** by `tests/Parity/UsersAuthGapParityTest.php`. The identity row is **VERIFIED** by `tests/Parity/IdentityAclParityTest.php`. The projects and issue nested sets row is **VERIFIED** by `tests/Parity/ProjectNestedSetParityTest.php`. The workflows row is **VERIFIED** by `tests/Parity/WorkflowParityTest.php`. The custom fields row is **VERIFIED** by `tests/Parity/CustomFieldParityTest.php`. The queries row is **VERIFIED** by `tests/Parity/IssueQueryParityTest.php`, `tests/Parity/CalendarGanttQueryParityTest.php`, and `tests/Parity/QueryRemainderParityTest.php`. The journals and private notes row is **VERIFIED** by `tests/Parity/JournalParityTest.php`. The time entries and attachments row is **VERIFIED** by `tests/Parity/TimeEntryParityTest.php` and `tests/Parity/AttachmentParityTest.php`. The outbound mail row is **VERIFIED** by `tests/Parity/NotificationParityTest.php`. The activity row is **VERIFIED** by `tests/Parity/ActivityParityTest.php`. The news, documents, and files row is **VERIFIED** by `tests/Parity/ModulesNewsDocumentsFilesParityTest.php`. The wiki row is **VERIFIED** by `tests/Parity/WikiParityTest.php`. The boards and forums row is **VERIFIED** by `tests/Parity/BoardsParityTest.php`. Notifications and activity for those modules are **VERIFIED** by the notification and activity tests. Calendar and Gantt are **VERIFIED** by `tests/Parity/CalendarGanttQueryParityTest.php`. Each of those tests loads `tests/Parity/fixtures/redmine-7.0.1/`. Textile and Markdown rendering is **VERIFIED** by `tests/Parity/MarkupParityTest.php`. Activity for changesets stays **NOT VERIFIED**. Every other P0 area is **NOT VERIFIED**. Passing the harness, or unit or feature tests, does not flip a checklist row.
- The HTTP API is not a general Redmine REST API. It is the Inertia health smoke page at `GET /`, the Inertia sign-in page at `GET /login` (Blade form at `?view=blade`), the account routes in [docs/users-auth-spec.md](docs/users-auth-spec.md), the auth probes `GET /users/current.json` and `GET /my.atom`, activity and issue Atom feeds at `GET /activity`, `GET /activity.atom`, `GET /issues.atom`, and the same paths under `GET /projects/{identifier}/`, the OAuth token and revoke routes, three custom-field routes (attachment download, attachment delete, and link URL resolution), the container attachment routes (`POST /attachments/upload`, `POST /attachments/claim`, `GET /attachments/{id}`, `GET /attachments/{id}/thumbnail`, `GET /attachments/{issues|journals|projects|versions|news|documents|wiki_pages|messages}/{id}/download`, `DELETE /attachments/{id}`), minimal news, document, and file pages, and minimal calendar, gantt, and project-list pages (`routes/web.php`). Those pages are not UI-ready, not Redmine UX parity, and not a 0.1 release (`docs/ux-parity-notes.md`). The custom fields checklist row is **VERIFIED** only by `tests/Parity/CustomFieldParityTest.php`. Container download, image thumbnails, and the download-all zip are compared on the time entries row. The download-all route also serves news, documents, wiki pages, and messages. Object type `files` is N/A in the 7.0.1 constraint; file zips are projects and versions. Wiki page and message zip bytes are also compared on the wiki and boards rows. Activity for issues, journals, and time entries is **VERIFIED** only by `tests/Parity/ActivityParityTest.php`. The news, documents, and files row is **VERIFIED** only by `tests/Parity/ModulesNewsDocumentsFilesParityTest.php`. The calendar and Gantt row is **VERIFIED** only by `tests/Parity/CalendarGanttQueryParityTest.php`.
- Journal rows are written on issue update (tracked attribute diffs, custom-value diffs, notes, private notes), on relation add, and by `JournalNoteService` for quote, edit, and delete of a note. Query code reads journals. Visible journals and rendered `attr`, `attachment`, `relation`, and `cf` detail lines are compared on the journals row by `tests/Parity/JournalParityTest.php`. The gate is [docs/journals-parity-gate.md](docs/journals-parity-gate.md). Container download, image thumbnail bytes, the download-all zip, and attachment or relation-removal journal writes are compared on the time entries row. SCM history stays open. Custom-field diffs are compared on the custom fields row. This is not a 0.1 tag.
- Projects, membership, workflow, and issues have an MVP smoke gate in [docs/acl-workflow-parity-gate.md](docs/acl-workflow-parity-gate.md). A core checklist row marked **PASS** there is Laramine smoke evidence from `tests/Feature/CoreChecklistSmokeTest.php`. That **PASS** is not the parity **VERIFIED** mark. The identity, nested-set, and workflows rows are **VERIFIED** only by the parity tests named in the checklist. Neither mark authorizes a 0.1 tag.
- Users and authentication are locked in [docs/users-auth-spec.md](docs/users-auth-spec.md) (2026-10-07). Phase 2 adds registration, `must_change_passwd`, and `recovery` / `register` tokens on the session guard. Phases 3–10 add LDAP through the in-memory directory, TOTP, the OAuth authorization-code server that 7.0.1 core ships, API and feed tokens, the user directory, account administration, preferences, and session / autologin tokens. Do not add a second auth stack, a bcrypt password column, Sanctum, Fortify, Jetstream, Passport, a live LDAP client, or OpenID Connect. The compared rows are not a 0.1 tag. `users_visibility` is compared on the identity row. Outbound mail for account and issue events is **VERIFIED** by `tests/Parity/NotificationParityTest.php`. News, document, file, message, and wiki mail are **VERIFIED** by that test. Activity for issues, journals, and time entries is **VERIFIED** by `tests/Parity/ActivityParityTest.php`. News, document, file, wiki, and message activity is **VERIFIED** by that test. Live LDAP, OpenID Connect, activity for changesets, and the rest of the REST API stay **NOT VERIFIED**. Textile and Markdown rendering is **VERIFIED** by `tests/Parity/MarkupParityTest.php`.
- Repository, git, SCM, settings behavior, and webhooks stay outside this slice. News, documents, and files are compared by `tests/Parity/ModulesNewsDocumentsFilesParityTest.php`. Wiki and forums are compared on their checklist rows. Calendar and Gantt are compared by `tests/Parity/CalendarGanttQueryParityTest.php`. Textile and Markdown rendering is **VERIFIED** by `tests/Parity/MarkupParityTest.php`. Those rows are not a 0.1 tag.
- Do not describe the tree as production-ready.

## Clean-room

Behavior and schema notes may follow Redmine 7.0.1 semantics. Do not copy GPLv2 Ruby (or other upstream source) into the tree. See [CONTRIBUTING.md](CONTRIBUTING.md).
