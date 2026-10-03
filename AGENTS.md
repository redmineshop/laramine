# Agent notes

Start here, then read the doc that matches the change. Laramine is a clean-room MIT Laravel core aimed at Redmine 7.0.1 semantics. The implementation is the code, the domain docs, and the tests. It is early work. [QUALITY.md](QUALITY.md) still has an open v1 checklist. Every row in [docs/parity-checklist.md](docs/parity-checklist.md) is **NOT VERIFIED**.

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

- Redmine parity is **NOT VERIFIED** for every P0 area. `tests/Parity` is a boot smoke test. Passing unit or feature tests does not flip a checklist row.
- The HTTP API is not implemented (`routes/web.php` is a placeholder).
- Journal rows are written on issue update (tracked attribute diffs, notes, private notes) and on relation add. Editing or deleting a journal is not implemented. Query code reads journals. The gate is [docs/journals-parity-gate.md](docs/journals-parity-gate.md). Parity for that gate is **NOT VERIFIED**.
- Projects, membership, workflow, and issues have an MVP smoke gate in [docs/acl-workflow-parity-gate.md](docs/acl-workflow-parity-gate.md). Passing it does not verify Redmine parity and does not authorize a 0.1 tag.
- Wiki, SCM, forums, news, settings behavior, and webhooks are outside the migrated P0 slice.
- Do not describe the tree as production-ready.

## Clean-room

Behavior and schema notes may follow Redmine 7.0.1 semantics. Do not copy GPLv2 Ruby (or other upstream source) into the tree. See [CONTRIBUTING.md](CONTRIBUTING.md).
