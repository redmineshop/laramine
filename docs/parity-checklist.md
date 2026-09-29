# Parity checklist

Structure pin: **Redmine 7.0.1**. Inventory: [schema-inventory.md](schema-inventory.md).

No row below is VERIFIED. A row becomes VERIFIED only when a fixture-backed test in `tests/Parity` compares Laramine behavior to that pin and the evidence path is filled in.

| Area | Status | Evidence |
| --- | --- | --- |
| P0 table and column layout | NOT VERIFIED | Migrations exist and a SQLite migrate smoke test passes. No dump diff against a Redmine 7.0.1 database has been recorded. |
| Identity, membership, and permissions | NOT VERIFIED | Tables only. Permission evaluation is not implemented. |
| Projects and issue nested sets | NOT VERIFIED | `lft` / `rgt` (and issue `root_id`) are stored. Tree maintenance is not implemented. |
| Workflows | NOT VERIFIED | `workflows` rows can be stored. Transitions are not evaluated. |
| Custom fields | NOT VERIFIED | Definition and value tables exist. Format validation is not implemented. |
| Queries | NOT VERIFIED | `filters` is opaque text. Operators are not implemented. |
| Time entries and attachments | NOT VERIFIED | Tables only. |
