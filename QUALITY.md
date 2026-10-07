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
- Shared fixtures aligned with Redmine Migrate Analyst P0 inventory
- Living `docs/parity-checklist.md`: VERIFIED / NOT VERIFIED / INCONCLUSIVE + evidence paths
- **No release claim** while any P0 row is failed or inconclusive
- Journal history has a gate in [docs/journals-parity-gate.md](docs/journals-parity-gate.md). The Block A and Block B smoke is Laramine behavior. It does not verify parity. Block C stays open.
- Projects, membership, workflow, and issues have a gate in [docs/acl-workflow-parity-gate.md](docs/acl-workflow-parity-gate.md). The MVP smoke is Laramine behavior. It does not verify parity and it is not a 0.1 tag. The open list in that file stays open.

## 0.1 path

There is no 0.1 tag. The ACL/workflow smoke above is not that tag.

Users and authentication are locked in [docs/users-auth-spec.md](docs/users-auth-spec.md) (founder date 2026-10-07). Phase 1 is web session sign-in with a Redmine-shaped password digest. The parity row is **NOT VERIFIED**. Later phases (registration, LDAP, two-factor, OAuth, tokens, account administration) are not done. This is not a 0.1 tag and not a production-ready claim. Journals, custom fields, and queries stay **NOT VERIFIED**.

## CI (every PR + default branch)

1. `composer install --prefer-dist --no-interaction`
2. Pint `--test`
3. PHPStan/Larastan
4. Full PHPUnit (Unit + Feature + Parity) against MySQL 8
5. Frontend job: `npm ci`, `tsc --noEmit`, Vite client and SSR build (Node 22). This checks the scaffold in [docs/frontend.md](docs/frontend.md). It does not make the UI ready, does not verify Redmine UX parity, and is not a 0.1 tag ([docs/ux-parity-notes.md](docs/ux-parity-notes.md)).
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
