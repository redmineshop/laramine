# Laramine

Open-source project management core on Laravel. The domain goal is a clean-room, Redmine-compatible model for projects, issues, workflows, memberships, and custom fields. Laramine does not include Redmine source.

P0 database migrations follow the Redmine 7.0.1 table and column layout for identity, projects, issues, custom fields, time entries, attachments, and saved queries. Nested-set columns on projects and issues are included. Domain behavior, permission checks, workflow rules, the query engine, and the HTTP API are not implemented, and Redmine parity is not claimed.

## Requirements

- PHP ^8.2 (8.2 or 8.3), with `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo`, `tokenizer`, and `xml`
- Composer 2
- Database: SQLite (default for local setup and CI) or MySQL 8

CI runs on PHP 8.3 with SQLite in memory.

## Install

```bash
git clone https://github.com/redmineshop/laramine.git
cd laramine
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

For MySQL 8, create an empty database and set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` before migrating.

## Schema

`php artisan migrate` creates the P0 tables described in [docs/schema-inventory.md](docs/schema-inventory.md), pinned to Redmine 7.0.1. A structure-only dump of that release is kept at [docs/sources/redmine-7.0.1-schema.rb](docs/sources/redmine-7.0.1-schema.rb). Wiki, SCM, forums, news, settings, and webhooks are not migrated.

The column names match that dump so a later ETL can load Redmine rows. Adapter differences (SQLite versus MySQL string lengths, integer width, and the `lower(login)` index) are listed at the bottom of the inventory. Eloquent models under `app/Models` are table stubs. Mass assignment is open except the primary key. Casts and relations are declared, and the models do not evaluate permissions, workflows, or query filters.

The Vite assets in this skeleton are optional. Tests and `php artisan serve` do not need Node. Use `npm install` and `npm run dev` only when you are changing frontend assets.

## Quality gates

These commands must pass before merge. Details and the v1 checklist are in [QUALITY.md](QUALITY.md).

| Gate | Command |
|------|---------|
| Pint | `composer lint` (`vendor/bin/pint --test`) |
| Larastan / PHPStan level 8 | `composer stan` (`vendor/bin/phpstan analyse`) |
| PHPUnit (Unit, Feature, Parity) | `composer test` |

PHPStan is locked at level 8 with no baseline. The Parity suite is wired and currently contains only a boot smoke test. It does not verify Redmine compatibility.

GitHub Actions runs Pint, PHPStan, and the full PHPUnit suite on every pull request and on pushes to `main`.

## License

[MIT](LICENSE). Copyright (c) 2026 Redmine Shop.
