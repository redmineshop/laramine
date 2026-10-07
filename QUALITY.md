# Quality bar — Laravel PM Core

First public / v1 ship must meet **all** gates below. Same spirit as Redmine core: automated tests are part of the product, not optional polish.

## Tools (locked in composer)

| Gate | Tool | Local command | CI |
|------|------|---------------|-----|
| Style | Laravel Pint | `vendor/bin/pint --test` | fail if dirty |
| Static analysis | Larastan (PHPStan) | `vendor/bin/phpstan analyse` | fail on any error |
| Unit | PHPUnit | `php artisan test --testsuite=Unit` | required |
| Feature / API | PHPUnit | `php artisan test --testsuite=Feature` | required |
| Parity (P0) | PHPUnit + fixtures | `php artisan test --testsuite=Parity` | required for release |

**Locked for v1:** PHPStan/Larastan **level 8** (user decision 2026-09-29). Raise toward max only after P0 ship; never lower without an explicit debt note.

## What must be tested

### Unit
- Custom field formats (parse, validate, cast, store shape)
- Query filter operators and composition
- ACL / membership permission checks (allow and deny)
- Workflow transitions (allowed / forbidden)
- Domain invariants (issue status, tracker, project membership)

### Feature / API
- CRUD issues & projects under auth
- Custom values round-trip on issues/projects
- Saved queries / filter result sets
- Permission denials (403 / empty forbidden scopes)
- Membership and role assignment happy + negative paths

### Parity
- Shared data pin: `tests/Parity/fixtures/redmine-7.0.1/` (invented, Redmine 7.0.1-shaped). `tests/Parity/Redmine701FixtureHarnessTest.php` proves the pin loads on MySQL 8. That pass is not **VERIFIED**.
- Shared fixtures aligned with Redmine Migrate Analyst P0 inventory
- Living `docs/parity-checklist.md`: VERIFIED / NOT VERIFIED / INCONCLUSIVE + evidence paths
- **No release claim** while any P0 row is failed or inconclusive
- Journal history has a gate in [docs/journals-parity-gate.md](docs/journals-parity-gate.md). The Block A, Block B, and Block C smoke is Laramine behavior. It does not verify parity. The journals checklist row is **VERIFIED** only by `tests/Parity/JournalParityTest.php`. Container download, image thumbnails, the download-all zip, and attachment or relation-removal writes are compared on the time entries row. SCM history stays open in that gate.
- Projects, membership, workflow, and issues have a gate in [docs/acl-workflow-parity-gate.md](docs/acl-workflow-parity-gate.md). The MVP smoke is Laramine behavior. The core checklist acceptance section may mark a happy path **PASS**. **PASS** is not **VERIFIED**. It does not verify parity and it is not a 0.1 tag. Per-tracker masks, `ManagedRoleGuard`, spent-time row visibility, archived and closed project gates, and close/reopen blockers are compared by the parity tests named in the checklist. Wiki, `copy_issues`, and the other rows still listed as open in that file stay open.

## 0.1 path

There is no 0.1 tag. The ACL/workflow smoke above is not that tag.

Users and authentication are locked in [docs/users-auth-spec.md](docs/users-auth-spec.md) (founder date 2026-10-07). Phase 2 is registration, `must_change_passwd`, and `recovery` / `register` tokens on the session guard, compared by `tests/Parity/UsersAuthParityTest.php`. Later phases are the users and authentication sub-rows, compared by `tests/Parity/UsersAuthGapParityTest.php` where that row is **VERIFIED**. Outbound mail is **VERIFIED** by `tests/Parity/NotificationParityTest.php`. Activity for issues, journals, and time entries is **VERIFIED** by `tests/Parity/ActivityParityTest.php`. News, document, and file notifications and activity are **VERIFIED** by that test and `tests/Parity/NotificationParityTest.php`. OpenID Connect, live LDAP, and the rest of the REST API stay **NOT VERIFIED**. `users_visibility` is compared on the identity row. The identity, projects nested-set, workflows, custom fields, queries, journals, time entries and attachments, and news, documents, and files rows are **VERIFIED** only by the parity tests named in the checklist. Wiki, boards and forums, and calendar and Gantt stay **NOT VERIFIED**. This is not a 0.1 tag and not a production-ready claim.

## CI (every PR + default branch)

1. `composer install --prefer-dist --no-interaction`
2. Pint `--test`
3. PHPStan/Larastan
4. Full PHPUnit (Unit + Feature + Parity) against MySQL 8
5. Frontend job: `npm ci`, `tsc --noEmit`, Vite client and SSR build (Node 22). This checks the pages in [docs/frontend.md](docs/frontend.md), including the sign-in screen. It does not make the UI ready, does not verify Redmine UX parity, and is not a 0.1 tag ([docs/ux-parity-notes.md](docs/ux-parity-notes.md)).
6. Matrix: at least one supported PHP version for v1; expand after first ship

Do not merge with red CI. Do not tag releases from a red commit.

## Ship checklist (v1)

- [ ] Pint `--test` pass
- [ ] PHPStan pass at locked level
- [ ] Unit suite pass with real domain coverage (not smoke-only)
- [ ] Feature/API suite pass for P0 behaviors
- [ ] Every P0 parity row VERIFIED with evidence
- [ ] CI green on default branch for that commit
- [ ] README (EN-first): install, PHP/DB versions, license, implemented vs not
- [ ] No copied Redmine (GPLv2) source — reimplementation only
- [ ] Public copy is to-be-state (no internal process meta)

## Anti-patterns

- Lint-only or syntax-only CI
- “Production-ready” in README while gates fail
- Claiming Redmine parity without fixture-backed runs
- Growing a PHPStan baseline silently
- Skipping negative permission tests

## PR expectations

Every PR notes: behavior changed, tests added/updated, parity rows touched (if any). Prefer small, reviewable PRs with green checks over large untested dumps.
