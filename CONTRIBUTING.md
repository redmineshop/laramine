# Contributing

Laramine is an early MIT project: a clean-room Laravel reimplementation of a Redmine-like project-management core. Behavior in the tree is what the domain docs and tests describe. [docs/parity-checklist.md](docs/parity-checklist.md) is the parity record. Every P0 row there is **NOT VERIFIED**. [QUALITY.md](QUALITY.md) is the quality bar. This file is how to change the code without weakening either.

## Ways to contribute

Open a pull request against `main`, or an issue when you want to discuss a behavior change before writing it. Small, reviewable changes are easier to land than a wide dump.

A pull request should say:

- what behavior changed
- which tests were added or updated
- which parity rows were touched, if any (most changes touch none)

Do not write that the product is production-ready. Do not write that Redmine parity is verified unless the checklist row has an evidence path and the Parity test that supports it is in the same change.

## Local setup

Follow [README.md](README.md). PHP `^8.2` (CI uses 8.3). The database for the app and for PHPUnit is **MySQL 8**.

Tests expect a database named `laramine_testing`, user `root`, password `password`, on `127.0.0.1:3306`. Those values are fixed in `phpunit.xml` (`force="true"`) and repeated in `.env.testing` and in `.github/workflows/ci.yml`. A SQLite `DB_CONNECTION` in `.env` does not redirect `php artisan test`.

SQLite is an optional local migrate smoke, already described in the README. It is not the suite CI runs. A SQLite migrate does not replace `composer test`.

## Checks before you open a pull request

Run these on MySQL 8. All three are required:

```bash
composer lint   # vendor/bin/pint --test
composer stan   # vendor/bin/phpstan analyse (Larastan, level 8)
composer test   # PHPUnit: Unit, Feature, and Parity
npm run typecheck
npm run build   # client bundle and Inertia SSR bundle (Node 22)
```

Focused PHPUnit while you work:

```bash
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --filter=IssueQuery
```

`composer test` is still the check that matches CI. There is no Pest configuration.

## CI

`.github/workflows/ci.yml` runs on every pull request and on pushes to `main`:

1. `composer install --prefer-dist --no-interaction`
2. Pint `--test`
3. PHPStan / Larastan at level 8
4. Full PHPUnit (Unit, Feature, Parity) against MySQL 8.0 on PHP 8.3
5. Frontend job: `npm ci`, `npm run typecheck`, `npm run build` on Node 22

The frontend job checks the Inertia pages, including the sign-in screen. It does not make the UI ready and it is not a 0.1 tag. See [docs/ux-parity-notes.md](docs/ux-parity-notes.md).

Green CI is required. Do not merge with a red check. Do not tag a release from a red commit. Do not lower the PHPStan level, skip a suite, switch CI to SQLite, or add a PHPStan baseline to hide errors. `phpstan.neon` has no baseline. A baseline would need an explicit debt note in [QUALITY.md](QUALITY.md) first, and this project is not adding one.

## Tests that match the bar

| Suite | What belongs there |
| --- | --- |
| Unit | Custom field formats, query operators, permission checks, workflow decisions, other domain invariants |
| Feature | Service behavior on MySQL: issues, projects, membership, custom values, saved queries, allow and deny paths |
| Parity | Fixture-backed comparison with Redmine 7.0.1 semantics, when that comparison exists |

`tests/Parity` boots the application and loads the invented pin at `tests/Parity/fixtures/redmine-7.0.1/` on MySQL 8. Loading that pin does not verify Redmine compatibility. Leave checklist rows at **NOT VERIFIED** until a Parity test compares behavior to the pin and the evidence path is filled in. See [docs/parity-checklist.md](docs/parity-checklist.md).

Domain feature tests use `Illuminate\Foundation\Testing\RefreshDatabase` and `Tests\Support\DomainFixture` where that fixture fits. Cover the deny path when you touch permissions.

## Clean-room rule

Redmine is GPLv2. Laramine is MIT. They must stay separate.

You may use published Redmine 7.0.1 **behavior and schema semantics** as the reference: the notes in `docs/`, and the structure-only dump at [docs/sources/redmine-7.0.1-schema.rb](docs/sources/redmine-7.0.1-schema.rb). Reimplement that behavior in original PHP.

Do not copy Redmine Ruby, ERB, JavaScript, fixtures, or tests into this tree, into a commit, or into a generated file. Do not vendor upstream source “for reference” inside the repository. Descriptions of tables, columns, and behavior are allowed. Upstream source is not.

If a change needs a new semantic note, write it in your own words in the matching doc under `docs/`. Record intentional differences in that doc. Do not present a difference as verified parity.

## Docs to update with the code

| Change | Update |
| --- | --- |
| Project tree, membership, permissions, issue workflow | [docs/domain.md](docs/domain.md) |
| Custom field formats or values | [docs/custom-fields.md](docs/custom-fields.md) |
| Saved queries or filter operators | [docs/queries.md](docs/queries.md) |
| P0 tables or columns | [docs/schema-inventory.md](docs/schema-inventory.md) and a migration |
| A real Redmine comparison | [docs/parity-checklist.md](docs/parity-checklist.md) and `tests/Parity` |
| Users or authentication | [docs/users-auth-spec.md](docs/users-auth-spec.md). Spec hole only. Do not implement login until a founder unlock. |
| Install, database, or CI commands | [README.md](README.md) and this file |
| Inertia scaffold, theme tokens, or page registration | [docs/frontend.md](docs/frontend.md) and [docs/ux-parity-notes.md](docs/ux-parity-notes.md) |

Skip new docs for a change that does not alter behavior, setup, or the quality bar. Do not add marketing pages.

## Agent kit

Coding agents should start at [AGENTS.md](AGENTS.md). Cursor rules are in `.cursor/rules/`. The domain continuation skill is `.cursor/skills/continue-domain-work/SKILL.md`. GitHub Copilot reads `.github/copilot-instructions.md`.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
