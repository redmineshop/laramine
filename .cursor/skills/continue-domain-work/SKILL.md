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
2. Read `docs/parity-checklist.md`. The P0 table and column layout row is **VERIFIED** by `tests/Parity/SchemaLayoutParityTest.php`. The users and authentication row is **VERIFIED** by `tests/Parity/UsersAuthParityTest.php`. The identity row is **VERIFIED** by `tests/Parity/IdentityAclParityTest.php`. The projects and issue nested sets row is **VERIFIED** by `tests/Parity/ProjectNestedSetParityTest.php`. The workflows row is **VERIFIED** by `tests/Parity/WorkflowParityTest.php`. The custom fields row is **VERIFIED** by `tests/Parity/CustomFieldParityTest.php`. The queries row is **VERIFIED** by `tests/Parity/IssueQueryParityTest.php`, `tests/Parity/CalendarGanttQueryParityTest.php`, and `tests/Parity/QueryRemainderParityTest.php`. The journals and private notes row is **VERIFIED** by `tests/Parity/JournalParityTest.php`. The time entries and attachments row is **VERIFIED** by `tests/Parity/TimeEntryParityTest.php` and `tests/Parity/AttachmentParityTest.php`. The outbound mail row is **VERIFIED** by `tests/Parity/NotificationParityTest.php`. The activity row is **VERIFIED** by `tests/Parity/ActivityParityTest.php`. The news, documents, and files row is **VERIFIED** by `tests/Parity/ModulesNewsDocumentsFilesParityTest.php`. The wiki row is **VERIFIED** by `tests/Parity/WikiParityTest.php`. The boards and forums row is **VERIFIED** by `tests/Parity/BoardsParityTest.php`. Calendar and Gantt are **VERIFIED** by `tests/Parity/CalendarGanttQueryParityTest.php`. Textile and Markdown rendering is **VERIFIED** by `tests/Parity/MarkupParityTest.php`. Activity for changesets stays **NOT VERIFIED**. Every other P0 row is **NOT VERIFIED**. Leave those rows that way unless you add a real comparison (see below). The schema row does not verify issues or queries.
3. Read the service you will change and one existing test that already covers a neighbor behavior.
4. Do not copy Redmine Ruby or other GPLv2 source. Reimplement from the semantic notes already in `docs/`. If you need a new semantic detail, write it in your own words in the doc.

## What already exists

| Area | Code | Tests | Doc status |
| --- | --- | --- | --- |
| Issue create/update, nested set, workflow, journals, time entries | `app/Domain/Issues/IssueService.php`, `app/Domain/Issues/IssueJournalWriter.php`, `app/Domain/Issues/JournalNoteService.php`, `app/Domain/Issues/IssueRelationService.php`, `app/Domain/Issues/History/IssueHistoryPresenter.php`, `app/Domain/TimeEntries/TimeEntryService.php`, `app/Domain/Attachments/AttachmentArchive.php`, `app/Domain/Workflow/WorkflowService.php` | `tests/Feature/IssueWorkflowTest.php`, `tests/Feature/IssueJournalSmokeTest.php`, `tests/Feature/IssueJournalBlockCTest.php`, `tests/Feature/IssueJournalNoteWriteTest.php`, `tests/Feature/IssueJournalHistoryLeftoversTest.php`, `tests/Feature/IssueJournalDetailLinesTest.php`, `tests/Feature/TimeEntryWriteTest.php`, `tests/Feature/AclWorkflowSmokeTest.php`, `tests/Feature/CoreChecklistSmokeTest.php`, `tests/Parity/JournalParityTest.php` | History tab filters, `#note-n` hrefs, the more menu, thumbnail notes, absolute copy links, download-all zips, and the Spent time and Associated revisions tabs are presentation or read models. `JournalNoteService` persists quote, edit, and delete. Visible journals and rendered detail lines are compared by `tests/Parity/JournalParityTest.php`. `TimeEntryService` creates, updates, and deletes time entries. `TimeEntryQueryRunner` runs a time-entry list, report, and CSV. Close and reopen blockers are applied by `IssueCloseGuard` and compared on the workflows row. Time entries and attachments are **VERIFIED** by `tests/Parity/TimeEntryParityTest.php` and `tests/Parity/AttachmentParityTest.php` (`docs/journals-parity-gate.md`). The ACL/workflow MVP smoke is NOT VERIFIED and is not a 0.1 tag (`docs/acl-workflow-parity-gate.md`). A **PASS** row in that gate's core checklist acceptance section is the MySQL smoke in `CoreChecklistSmokeTest`, not a parity **VERIFIED** row. |
| Custom field formats and values | `app/Domain/CustomFields/`, `app/Domain/Attachments/AttachmentService.php` | `tests/Unit/CustomFieldFormatTest.php`, `tests/Unit/CustomFieldRecordFormatTest.php`, `tests/Feature/CustomFieldValueTest.php`, `tests/Feature/CustomFieldAttachmentUploadTest.php`, `tests/Feature/CustomFieldEnumerationOptionTest.php`, `tests/Feature/CustomFieldAssetHttpTest.php`, `tests/Feature/CustomFieldJournalTest.php`, `tests/Parity/CustomFieldParityTest.php` | All 13 format keys store values. Attachment files are written and bound when the custom value is saved. Enumeration options can be inserted, reordered, activated, and deleted. Deleting an option that values still store rewrites those ids onto another option of the same field. Link URL patterns encode tokens and the link route returns the outbound URL without requesting it. Version values follow `versions.sharing`. Issue update writes `cf` journal diffs. Authorized attachment download, attachment delete, and link URL resolution are HTTP routes. The checklist row is **VERIFIED** only by the parity test (`docs/custom-fields-deferred-parity-gate.md`). |
| Issue query compile and run | `app/Domain/Queries/` (`IssueQueryRunner`, `IssueQueryCompiler`, `IssueFilterCatalog`, `OperatorMatrix`, `IssueQueryTotals`, `IssueQuerySort`, `IssueQueryProjection`) | `tests/Unit/IssueQueryOperatorTest.php`, `tests/Unit/IssueQueryFieldTest.php`, `tests/Unit/IssueQueryDisplayTest.php`, `tests/Feature/IssueQueryTest.php`, `tests/Feature/IssueQuerySortTest.php`, `tests/Feature/IssueQueryDepthTest.php` | Catalog operators are compiled. Unknown fields and operators are rejected, not treated as match-all. `options.totalable_names` sums `estimated_hours`, `estimated_remaining_hours`, `spent_hours`, and visible int/float `cf_{id}` columns. `spent_hours` and the `spent_time` filter follow `time_entries_visibility`. Sort orders priority, status, and tracker by position, author and assignee by firstname then lastname, project, category, and fixed version by name, and visible `cf_{id}` by one minimum value. `present` applies `list` or `board` and projects available columns (`docs/queries.md`). List-like custom fields compile `ev` / `!ev` / `cf` against `journal_details.property = cf`. History presentation of those `cf` rows is compared on the journals row (`tests/Parity/JournalParityTest.php`). The checklist row is **VERIFIED** by `tests/Parity/IssueQueryParityTest.php`, `tests/Parity/CalendarGanttQueryParityTest.php`, and `tests/Parity/QueryRemainderParityTest.php`. |
| Saved query visibility | `app/Domain/Queries/SavedQueryService.php`, `app/Domain/Queries/ProjectQueryRunner.php` | `tests/Feature/IssueQueryTest.php`, `tests/Feature/QueryRemainderTest.php`, `tests/Parity/TimeEntryParityTest.php`, `tests/Parity/CalendarGanttQueryParityTest.php`, `tests/Parity/QueryRemainderParityTest.php` | `TimeEntryQuery` runs. `UserQuery` runs the catalog in `UserQueryCatalog`. `ProjectQuery` and `ProjectAdminQuery` run through `ProjectQueryRunner`, including project custom fields and `last_activity_date`. Descendant hour columns and `estimated_remaining_hours` are on `IssueQuery`. Changeset activity stays N/A with the pin SCM excludes. |

`IssueService` is the issue write path, including custom values. `CustomValueService` does not authorize the host record. Callers other than `IssueService` must already have checked the host permission.

Issue update writes `journal_details` for tracked attributes. Query history reads those rows. It does not write them.

Custom-field attachment download and link resolution are already routes in `routes/web.php`. Do not add further routes unless the task explicitly asks for HTTP.

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

- a test under `tests/Parity` compares this tree to the pin at `tests/Parity/fixtures/redmine-7.0.1/` (loaded by `Tests\Parity\Support\Redmine701Fixture`) or to a recorded result at `tests/Parity/fixtures/redmine-7.0.1/expectations/<area>/<case>.json`
- the checklist **Evidence** cell cites that test and the fixture or expectation path
- the pull request does not say the whole product matches Redmine

`tests/Parity/Redmine701FixtureHarnessTest.php` only proves the pin loads on MySQL 8. It does not satisfy the rules above. `tests/Parity/SchemaLayoutParityTest.php` is the structure compare for the schema row. It does not satisfy the rules for a behavior row.

Otherwise leave **NOT VERIFIED**. **INCONCLUSIVE** is for a comparison that ran and did not decide, with the evidence path filled in. Do not use it as a soft pass.

## Docs and PR text

Update the one domain doc that owns the behavior. Skip a new markdown file when an existing doc can take a section.

PR text should list behavior changed, tests added or updated, and parity rows touched (usually “none”). Do not write “production-ready”, “Redmine compatible”, or “parity verified” for this work.

## Out of scope unless the task says otherwise

- Copying upstream source into the repository
- A public JSON API beyond the users and authentication routes, or a second auth stack. Users and authentication are locked in `docs/users-auth-spec.md`. Do not rework LDAP, two-factor, OAuth, API tokens, or account admin from an issues, queries, or custom-fields task. Those checklist rows are not a 0.1 tag.
- Mail or full-text search
- Wiki, SCM, forums, news, webhooks
- Switching CI to SQLite or lowering PHPStan
- Editing `docs/community-metrics.md` (KPI definitions, not domain behavior)
