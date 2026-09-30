# Laramine

Open-source project management core on Laravel. The domain goal is a clean-room, Redmine-compatible model for projects, issues, workflows, memberships, and custom fields. Laramine does not include Redmine source.

P0 database migrations follow the Redmine 7.0.1 table and column layout for identity, projects, issues, custom fields, time entries, attachments, and saved queries. Nested-set columns on projects and issues are included.

Domain services cover project trees, membership and permission checks, issue create/update with workflow transitions, custom field formats and values, and saved issue queries with the issue filter-operator catalog. Issue queries can read existing journals and issue relations. Journal writing and the HTTP API are not implemented. Redmine parity is not claimed. Behavior, the permission JSON codec, and seeded roles are described in [docs/domain.md](docs/domain.md). Custom fields are described in [docs/custom-fields.md](docs/custom-fields.md). Queries are described in [docs/queries.md](docs/queries.md).

## Requirements

- PHP ^8.2 (8.2 or 8.3), with `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, and `xml`
- Composer 2
- MySQL 8 (supported database for the app and for tests)

CI runs on PHP 8.3 against a MySQL 8.0 service. SQLite is an optional local smoke path and is not authoritative: PHPUnit and GitHub Actions do not use it.

## Install

```bash
git clone https://github.com/redmineshop/laramine.git
cd laramine
composer install
cp .env.example .env
php artisan key:generate
```

Create a MySQL 8 database named `laramine`, then set these values in `.env` before migrating:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laramine
DB_USERNAME=root
DB_PASSWORD=password
```

```bash
php artisan migrate
php artisan serve
```

Tests need a second database, `laramine_testing`, reachable as `root` / `password` on `127.0.0.1:3306`. Those credentials are fixed in `phpunit.xml` (`force="true"`) and repeated in `.env.testing`. `php artisan test` starts PHPUnit in a new process, so a SQLite `DB_CONNECTION` in `.env` does not redirect the suite. The GitHub Actions job provisions MySQL 8.0 with the same database name, user, and password; it does not override `phpunit.xml`.

```bash
composer test
```

SQLite remains available for a quick local migrate smoke only. That path is non-authoritative and is not what CI runs:

```bash
touch database/database.sqlite
# keep DB_CONNECTION=sqlite in .env
php artisan migrate
```

## Schema

`php artisan migrate` creates the P0 tables described in [docs/schema-inventory.md](docs/schema-inventory.md), pinned to Redmine 7.0.1. A structure-only dump of that release is kept at [docs/sources/redmine-7.0.1-schema.rb](docs/sources/redmine-7.0.1-schema.rb). Wiki, SCM, forums, news, settings, and webhooks are not migrated.

The column names match that dump so a later ETL can load Redmine rows. Adapter differences (SQLite versus MySQL string lengths, integer width, and the `lower(login)` index) are listed at the bottom of the inventory. Eloquent models under `app/Models` map those tables. Project, membership, and issue services maintain nested sets and evaluate permission names and workflow rows. Custom field formats validate and store `custom_values`. Issue query filters are evaluated in `app/Domain/Queries` and stored as JSON.

The Vite assets in this skeleton are optional. Tests and `php artisan serve` do not need Node. Use `npm install` and `npm run dev` only when you are changing frontend assets.

## Quality gates

These commands must pass before merge. Details and the v1 checklist are in [QUALITY.md](QUALITY.md).

| Gate | Command |
|------|---------|
| Pint | `composer lint` (`vendor/bin/pint --test`) |
| Larastan / PHPStan level 8 | `composer stan` (`vendor/bin/phpstan analyse`) |
| PHPUnit (Unit, Feature, Parity) | `composer test` |

PHPStan is locked at level 8 with no baseline. The Parity suite is wired and currently contains only a boot smoke test. It does not verify Redmine compatibility.

GitHub Actions runs Pint, PHPStan, and the full PHPUnit suite on MySQL 8 for every pull request and on pushes to `main`. There is no Pest configuration.

## Contributing

[CONTRIBUTING.md](CONTRIBUTING.md) covers local checks, CI, and the clean-room rule. [SECURITY.md](SECURITY.md) is how to report a vulnerability. The quality bar is [QUALITY.md](QUALITY.md).

Coding agents: start at [AGENTS.md](AGENTS.md). Cursor rules are in `.cursor/rules/`. The skill for issues, queries, and custom fields is `.cursor/skills/continue-domain-work/SKILL.md`. Copilot instructions are in `.github/copilot-instructions.md`.

Parity for every P0 row in [docs/parity-checklist.md](docs/parity-checklist.md) is **NOT VERIFIED**. [QUALITY.md](QUALITY.md) still lists the v1 ship checklist as open. Community count definitions, separate from product status, are in [docs/community-metrics.md](docs/community-metrics.md).

## License

[MIT](LICENSE). Copyright (c) 2026 Redmine Shop.
