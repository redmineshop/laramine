# Parity checklist

Structure pin: **Redmine 7.0.1**. Inventory: [schema-inventory.md](schema-inventory.md).

No row below is VERIFIED. A row becomes VERIFIED only when a fixture-backed test in `tests/Parity` compares Laramine behavior to that pin and the evidence path is filled in.

| Area | Status | Evidence |
| --- | --- | --- |
| P0 table and column layout | NOT VERIFIED | Migrations exist and a MySQL 8 migrate smoke test passes. No dump diff against a Redmine 7.0.1 database has been recorded. |
| Identity, membership, and permissions | NOT VERIFIED | `tests/Feature/MembershipAclTest.php` and `tests/Unit/PermissionCatalogTest.php` exercise Laramine allow/deny rules. They do not compare rows with a Redmine 7.0.1 database. |
| Projects and issue nested sets | NOT VERIFIED | `tests/Feature/ProjectTreeTest.php` and `tests/Feature/IssueWorkflowTest.php` check lft/rgt integrity after create and move. No Redmine dump diff. |
| Workflows | NOT VERIFIED | `tests/Feature/IssueWorkflowTest.php` checks transition allow/deny, including `old_status_id = 0`. No Redmine dump diff. |
| Custom fields | NOT VERIFIED | `tests/Unit/CustomFieldFormatTest.php`, `tests/Unit/CustomFieldRecordFormatTest.php`, and `tests/Feature/CustomFieldValueTest.php` exercise format validation and value writes on MySQL. They do not compare rows with a Redmine 7.0.1 database. |
| Queries | NOT VERIFIED | `filters` is opaque text. Operators are not implemented. |
| Time entries and attachments | NOT VERIFIED | Tables only. |
