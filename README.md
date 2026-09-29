# Laramine

Open-source project management core on Laravel. The domain goal is a clean-room, Redmine-compatible model for projects, issues, workflows, memberships, and custom fields. Laramine does not include Redmine source.

This repository is the application skeleton. Quality tooling (style, static analysis, and tests) is in place. Product behavior for issues, projects, access control, and custom fields is not implemented yet, and Redmine parity is not claimed.

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
