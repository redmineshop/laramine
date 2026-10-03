---
name: continue-domain-work
description: Continue Laramine domain work on issues, saved queries, and custom fields without claiming Redmine parity. Use when adding or changing issue workflow, issue query filters, operators, or custom field formats and values.
---

# Continue domain work

Use this when extending issues, issue queries, or custom fields. The source of truth is the current PHP plus the domain docs, not an assumption that Laramine already matches Redmine.

## Before editing

1. Read the doc for the area, including **Intentional differences** and any **Deferred** section:
   - Issues, workflow, permissions, project tree: `docs/domain.md`
   - Custom fields: `docs/custom-fields.md`
   - Queries and operators: `docs/queries.md`
2. Read `docs/parity-checklist.md`. Every P0 row is **NOT VERIFIED**. Leave it that way unless you add a real comparison (see below).
3. Read the service you will change and one existing test that already covers a neighbor behavior.
4. Do not copy Redmine Ruby or other GPLv2 source. Reimplement from the semantic notes already in `docs/`. If you need a new semantic detail, write it in your own words in the doc.

## What already exists

| Area | Code | Tests | Doc status |
| --- | --- | --- | --- |
| Issue create/update, nested set, workflow, journals | `app/Domain/Issues/IssueService.php`, `app/Domain/Issues/IssueJournalWriter.php`, `app/Domain/Issues/IssueRelationService.php`, `app/Domain/Issues/History/IssueHistoryPresenter.php`, `app/Domain/Workflow/WorkflowService.php` | `tests/Feature/IssueWorkflowTest.php`, `tests/Feature/IssueJournalSmokeTest.php` | Journal edit and delete are not implemented. Blocker rules on close/reopen are not applied. Parity for journals is NOT VERIFIED (`docs/journals-parity-gate.md`). |
| Custom field formats and values | `app/Domain/CustomFields/` | `tests/Unit/CustomFieldFormatTest.php`, `tests/Unit/CustomFieldRecordFormatTest.php`, `tests/Feature/CustomFieldValueTest.php` | `link`, `enumeration`, `attachment`, `progressbar` can be defined and reject non-blank values. |
| Issue query compile and run | `app/Domain/Queries/` (`IssueQueryRunner`, `IssueQueryCompiler`, `IssueFilterCatalog`, `OperatorMatrix`) | `tests/Unit/IssueQueryOperatorTest.php`, `tests/Unit/IssueQueryFieldTest.php`, `tests/Feature/IssueQueryTest.php` | Catalog operators are compiled. Unknown fields and operators are rejected, not treated as match-all. |
| Saved query visibility | `app/Domain/Queries/SavedQueryService.php` | `tests/Feature/IssueQueryTest.php` | `ProjectQuery`, `TimeEntryQuery`, `UserQuery`, `ProjectAdminQuery` store empty filters and do not run. |

`IssueService` is the issue write path, including custom values. `CustomValueService` does not authorize the host record. Callers other than `IssueService` must already have checked the host permission.

Issue update writes `journal_details` for tracked attributes. Query history reads those rows. It does not write them.

The HTTP API is not part of these slices. Do not add routes unless the task explicitly asks for HTTP.

## How to extend

1. Prefer the existing service, format class, or filter catalog. Add a format key only on `App\Domain\CustomFields\FieldFormatKey`, and keep `implemented()` exhaustive.
2. Fail closed. A deferred format, unknown operator, or unknown field is an error (`CustomFieldValidationException` or `QueryValidationException`). Do not return an unfiltered issue set.
3. Keep stored shapes that the docs already specify: JSON filter maps, JSON `possible_values` / `format_store`, JSON permission arrays, Redmine STI names, `custom_values` as one row per value.
4. If the new behavior differs from the Redmine 7.0.1 semantic note, add a bullet under **Intentional differences** in the same doc. If it matches a note you just learned, write the note as behavior, still without a parity claim.
5. Update the operator or format table when the catalog changes. Remove a name from the deferred table only when the code and a test both cover it.

## Tests

MySQL 8 only (`phpunit.xml` forces `laramine_testing`). Use `RefreshDatabase`.

- Operator or format logic that can run without a full project: `tests/Unit`.
- Persistence, visibility, workflow, and permissions: `tests/Feature`, starting from `Tests\Support\DomainFixture` when the fixture’s project, tracker, statuses, and member fit.
- Include a deny or validation-failure case when you add a permission, required field, or rejected operator.
- Name methods `test_*`. Assert the stored row and the error type, not only a happy-path return value.

```bash
php artisan test --testsuite=Unit --filter=IssueQuery
php artisan test --testsuite=Feature --filter=CustomField
composer lint
composer stan
composer test
```

`composer test` is the CI-equivalent run. Pint and PHPStan level 8 stay required. Do not add a PHPStan baseline.

## Parity

A green Unit or Feature test is Laramine behavior. It is not a Redmine comparison.

Change a `docs/parity-checklist.md` row to **VERIFIED** only when all of these are true:

- a test under `tests/Parity` compares this tree to a pinned Redmine 7.0.1 fixture or recorded result
- the checklist **Evidence** cell cites that test
- the pull request does not say the whole product matches Redmine

Otherwise leave **NOT VERIFIED**. **INCONCLUSIVE** is for a comparison that ran and did not decide, with the evidence path filled in. Do not use it as a soft pass.

## Docs and PR text

Update the one domain doc that owns the behavior. Skip a new markdown file when an existing doc can take a section.

PR text should list behavior changed, tests added or updated, and parity rows touched (usually “none”). Do not write “production-ready”, “Redmine compatible”, or “parity verified” for this work.

## Out of scope unless the task says otherwise

- Copying upstream source into the repository
- HTTP controllers, auth screens, or a public JSON API
- Editing or deleting journals, mail, or full-text search
- Wiki, SCM, forums, news, webhooks
- Switching CI to SQLite or lowering PHPStan
- Editing `docs/community-metrics.md` (KPI definitions, not domain behavior)
