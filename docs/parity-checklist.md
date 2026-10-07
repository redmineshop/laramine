# Parity checklist

Structure pin: **Redmine 7.0.1**. Inventory: [schema-inventory.md](schema-inventory.md).

The P0 table and column layout row is **VERIFIED** only by the test named in its Evidence cell. Every other row stays **NOT VERIFIED**. A behavior row becomes **VERIFIED** only when a fixture-backed test in `tests/Parity` compares Laramine behavior to the shared data pin and the evidence path is filled in. Path rules are under [Evidence paths](#evidence-paths). Loading the shared pin is not that comparison. This checklist is not a 0.1 tag.

| Area | Status | Evidence |
| --- | --- | --- |
| P0 table and column layout | VERIFIED | `tests/Parity/SchemaLayoutParityTest.php` loads `tests/Parity/fixtures/redmine-7.0.1/` through `Tests\Parity\Support\Redmine701Fixture` on MySQL 8 and compares the migrated tables, columns, nullability, defaults, types, dump indexes, and foreign keys to `docs/sources/redmine-7.0.1-schema.rb`. The compare is the 41 P0 tables plus `settings`. Repository, git, and SCM tables are excluded (`changes` and `changeset_parents` stay unmigrated). Adapter differences accepted by that test are the ones named in [schema-inventory.md](schema-inventory.md). This row does not verify any other area and is not a 0.1 tag. |
| Identity, membership, and permissions | NOT VERIFIED | `tests/Feature/MembershipAclTest.php`, `tests/Unit/PermissionCatalogTest.php`, and `tests/Feature/AclWorkflowSmokeTest.php` exercise Laramine allow/deny rules. `tests/Feature/CoreChecklistSmokeTest.php` stores a custom-role membership and checks `add_issues` allow and deny. They do not compare rows with a Redmine 7.0.1 database. Open items are listed in [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md). Authentication is a separate row. |
| Users and authentication | NOT VERIFIED | Founder lock 2026-10-07 in [users-auth-spec.md](users-auth-spec.md). Phase 1 session sign-in is Laramine behavior (`tests/Unit/RedminePasswordTest.php`, `tests/Feature/SessionLoginTest.php`). The Inertia sign-in page posts to that same action (`tests/Feature/InertiaLoginPageTest.php`) and is not a Redmine screen comparison. LDAP, two-factor, OAuth, tokens, registration, and account admin are later phases. No `tests/Parity` comparison. This row is not a 0.1 tag. |
| Projects and issue nested sets | NOT VERIFIED | `tests/Feature/ProjectTreeTest.php` and `tests/Feature/IssueWorkflowTest.php` check lft/rgt integrity after create and move. `tests/Feature/AclWorkflowSmokeTest.php` checks `inherit_members` on a child created after the membership. `tests/Feature/CoreChecklistSmokeTest.php` stores a child inside its parent and an issue root at `lft = 1`. No Redmine dump diff. |
| Workflows | NOT VERIFIED | `tests/Feature/IssueWorkflowTest.php` checks transition allow/deny, including `old_status_id = 0`. `tests/Feature/AclWorkflowSmokeTest.php` checks field-rule merge, assignee-group transitions, and admin bypass. `tests/Feature/CoreChecklistSmokeTest.php` stores a non-default initial status from `old_status_id = 0` and the next status. No Redmine dump diff. Open items are listed in [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md). |
| Custom fields | NOT VERIFIED | `tests/Unit/CustomFieldFormatTest.php`, `tests/Unit/CustomFieldRecordFormatTest.php`, `tests/Feature/CustomFieldValueTest.php`, `tests/Feature/CustomFieldAttachmentUploadTest.php`, `tests/Feature/CustomFieldEnumerationOptionTest.php`, and `tests/Feature/CustomFieldAssetHttpTest.php` exercise format validation, value writes, attachment upload and bind, enumeration option edits and deletion, link URL encoding, authorized attachment download, and link URL resolution on MySQL. They do not compare rows with a Redmine 7.0.1 database. Open items are listed in [custom-fields-deferred-parity-gate.md](custom-fields-deferred-parity-gate.md). |
| Queries | NOT VERIFIED | `tests/Unit/IssueQueryOperatorTest.php`, `tests/Unit/IssueQueryFieldTest.php`, `tests/Unit/IssueQueryDisplayTest.php`, `tests/Feature/IssueQueryTest.php`, `tests/Feature/IssueQuerySortTest.php`, and `tests/Feature/IssueQueryDepthTest.php` exercise Laramine operators, saved-query visibility, custom field filters, query totals, sort order, `list`/`board` display, column projection, and `spent_hours` / `spent_time` visibility on MySQL. They do not compare rows with a Redmine 7.0.1 database. Open result-depth items are listed in [queries.md](queries.md). |
| Journals and private notes | NOT VERIFIED | `tests/Feature/IssueJournalSmokeTest.php`, `tests/Feature/IssueJournalBlockCTest.php`, `tests/Feature/IssueJournalNoteWriteTest.php`, `tests/Feature/IssueJournalHistoryLeftoversTest.php`, `tests/Unit/JournalPresentationTest.php`, and `tests/Unit/JournalQuoteTextTest.php` exercise Laramine journal writes, quote / edit / delete of a note, history presentation, private-note visibility, history-tab filters, `#note-n` hrefs, note-control permissions, download-all zips, thumbnail notes, absolute copy links, and the Spent time and Associated revisions tabs. They do not compare rows with a Redmine 7.0.1 database. Deferred rows stay in [journals-parity-gate.md](journals-parity-gate.md). |
| Time entries and attachments | NOT VERIFIED | The issue history Spent time tab reads `time_entries` and applies `time_entries_visibility` (`tests/Feature/IssueJournalHistoryLeftoversTest.php`). IssueQuery `spent_hours` totals, the projected column, and the `spent_time` filter use that same `all` / `own` rule (`tests/Feature/IssueQueryDepthTest.php`, `tests/Unit/IssueQueryFieldTest.php`). `TimeEntryService` creates, updates, and deletes rows (`tests/Feature/TimeEntryWriteTest.php`). An attachment custom field can store a file on the local `attachments` disk and bind the row when the value is saved (`tests/Feature/CustomFieldAttachmentUploadTest.php`). `GET /custom-fields/attachments/{id}` serves that file while a custom value still stores the id (`tests/Feature/CustomFieldAssetHttpTest.php`). Download all files zips issue and journal attachments in the leftovers test. Those journal and issue files are not served over HTTP. There is no Redmine dump diff. |

## Full smoke after slices 1–4

Recorded **2026-10-07** on tip `56b74184e38388ab0fb1cc527f06067593e6adac`, after founder slices #23 (`spent_time`), #24 (custom-field HTTP), #22 (checklist smoke), and #25 (Inertia login) were on that tip.

This is a Laramine quality-bar re-run on that checkout (PHP 8.3, MySQL 8.0, Node 22). **PASS** means those commands finished green. It is not a Redmine 7.0.1 comparison, it does not move any row above to **VERIFIED**, and it is not a 0.1 tag.

| Check | Result |
| --- | --- |
| Pint (`composer lint`) | PASS |
| PHPStan level 8 (`composer stan`, 186 files) | PASS |
| PHPUnit on MySQL 8 (`composer test`) | PASS — 181 tests, 1960 assertions (Unit, Feature, and the Parity boot smoke) |
| `tests/Feature/CoreChecklistSmokeTest.php` | PASS — 4 tests, in the same PHPUnit run |
| Frontend (`npm run typecheck`, `npm run build`, client and SSR) | PASS |

Custom fields, queries, journals, users and authentication, and UX stay **NOT VERIFIED**. The Inertia pages are not UI-ready ([ux-parity-notes.md](ux-parity-notes.md)). `tests/Parity` still only boots the application. Checklist **PASS** is not Redmine parity **VERIFIED**.

That paragraph describes tip `56b74184e38388ab0fb1cc527f06067593e6adac`. The data pin added later is under Evidence paths. Loading that pin does not by itself move a row to **VERIFIED**. The schema layout row above is a later structure compare against the schema pin.

## Evidence paths

Shared data pin: `tests/Parity/fixtures/redmine-7.0.1/`.

The pin is invented and labeled `redmine-7.0.1-shaped`. It is not a copy of Redmine fixtures or source. [`sources/redmine-7.0.1-schema.rb`](sources/redmine-7.0.1-schema.rb) is the schema pin only. It is not this data fixture. Repository, git, and SCM tables (`repositories`, `changesets`, `changes`, `changeset_parents`, `changesets_issues`) stay out of the pin.

| Path | Role |
| --- | --- |
| `tests/Parity/fixtures/redmine-7.0.1/manifest.json` | Pin `7.0.1`, origin `invented`, load order, and row counts |
| `tests/Parity/fixtures/redmine-7.0.1/<table>.json` | Rows for one table named in the manifest |
| `tests/Parity/fixtures/redmine-7.0.1/expectations/<area>/<case>.json` | Recorded result a later slice compares against. Add the file with that slice. |
| `tests/Parity/Support/Redmine701Fixture.php` | Loads the pin on MySQL 8 |
| `tests/Parity/Redmine701FixtureHarnessTest.php` | Proves the pin loads. Not a domain comparison. |
| `tests/Parity/<Area>ParityTest.php` | Later comparison test. One area per class. |

The P0 table and column layout row is **VERIFIED** by `tests/Parity/SchemaLayoutParityTest.php`. That test loads `tests/Parity/fixtures/redmine-7.0.1/` through `Tests\Parity\Support\Redmine701Fixture` on MySQL 8 and compares migrated structure to `docs/sources/redmine-7.0.1-schema.rb`. It does not compare issue, query, workflow, or permission behavior, and it does not verify any other row.

A later slice marks one other row **VERIFIED** only when all of these are true:

1. A test under `tests/Parity` loads `tests/Parity/fixtures/redmine-7.0.1/` through `Tests\Parity\Support\Redmine701Fixture` on MySQL 8.
2. That test compares Laramine behavior to rows in the pin, or to a file under `tests/Parity/fixtures/redmine-7.0.1/expectations/`.
3. The Evidence cell of that one row cites the test class and the fixture or expectation path.
4. The pull request does not say the whole product matches Redmine.

`Redmine701FixtureHarnessTest` does not satisfy those rules. Every row except P0 table and column layout stays **NOT VERIFIED**.

**INCONCLUSIVE** is for a comparison that ran and did not decide. The Evidence cell still cites the test and the fixture path. Do not use it as a soft pass.
