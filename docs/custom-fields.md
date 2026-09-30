# Custom fields

Laramine stores custom fields in the Redmine 7.0.1 tables (`custom_fields`, `custom_values`, `custom_fields_trackers`, `custom_fields_projects`, `custom_fields_roles`, `custom_field_enumerations`). This is not a Redmine parity claim. Journal diffs and the HTTP API are not part of this slice. Issue query filters for `is_filter` fields are described in [queries.md](queries.md).

## Storage

`custom_fields.type` is the STI name. `custom_values.customized_type` is the Redmine class name, not the Eloquent class name.

| `custom_fields.type` | `customized_type` | Scope |
| --- | --- | --- |
| `IssueCustomField` | `Issue` | Tracker link, and `is_for_all` or a project link |
| `ProjectCustomField` | `Project` | The project record |
| `UserCustomField` | `User` | Users with `type = User` |
| `GroupCustomField` | `Group` | Users with `type = Group` |
| `TimeEntryCustomField` | `TimeEntry` | The time entry; visibility uses its project |
| `VersionCustomField` | `Version` | The version; visibility uses its project |

`possible_values` and `format_store` are JSON text in the existing columns. A non-JSON legacy value decodes as null. No migration was added for that codec.

Multiple values are one `custom_values` row per entry. A blank value deletes the rows for that field. Bool values are the strings `1` and `0`. User and version values are id strings. Dates are `YYYY-MM-DD`.

## Formats

| `field_format` | Values | Notes |
| --- | --- | --- |
| `string`, `text` | One string | Optional regexp, min length, max length. Searchable flag allowed. `text_formatting` is stored and not rendered. |
| `int` | `/^[+-]?\d+$/`, stored without a leading `+` | Must fit in a PHP integer. |
| `float` | Decimal text, no exponent | The trimmed text is stored. |
| `date` | `YYYY-MM-DD` | `format_store.default_value_mode` is `fixed_date` or `date_offset` (days from today). |
| `list` | One or many entries from `possible_values` | The only single-line format that allows `multiple`, together with `user` and `version`. |
| `bool` | `1` or `0` | `true` / `false` / `1` / `0` are accepted. `yes` and `true` text are rejected. |
| `user` | Active user id | On a project record (issue, time entry, version, project) the user must be a member. `format_store.user_role` limits roles. |
| `version` | Version id on the same project | `format_store.version_status` may list `open`, `locked`, `closed`. Sharing across projects is not applied. |
| `link`, `enumeration`, `attachment`, `progressbar` | Not supported | The key can be saved on a definition. A non-blank value is rejected. Attachment files, enumeration rows, link URLs, and progress steps are deferred. |

`multiple` is rejected on formats that do not support it. `searchable` is rejected the same way. `is_filter` marks an issue custom field as usable in an IssueQuery. Implemented formats (string, text, int, float, date, list, bool, user, version) compile `cf_{id}` filters. See [queries.md](queries.md). Link, enumeration, attachment, and progressbar stay unfiltered.

`CustomFieldService::save` writes the definition and the tracker, project, and role links. Issue fields need at least one tracker, and either `is_for_all` or one project. `is_for_all` is rejected on other types. Names are unique per STI type and at most 30 characters.

## Reads and writes

`CustomValueService::sync` replaces values for the fields in the payload. Omitted fields keep their rows. On issue create, omitted fields receive `default_value` when it validates. The read shape is:

```json
{ "id": 1, "name": "Customer", "field_format": "list", "value": ["A"], "raw": ["A"] }
```

`value` is the cast PHP value. `raw` is the stored strings. Hidden fields are omitted from the read.

`IssueService::create` and `update` accept:

```json
{ "custom_fields": [{ "id": 1, "value": "A" }, { "id": 2, "value": ["10", "11"] }] }
```

Project, user, group, time entry, and version values use `CustomValueService` directly. That call checks field rules only. It does not repeat `edit_project`, user administration, or time-entry permissions; the caller does.

Validation failures raise `CustomFieldValidationException` (a `DomainException`). The message is `Name: first error`. `errors` lists every field that failed.

## Visibility and editability

`visible = true` shows the field to anyone who can see the record through this service. `visible = false` shows it when the actor is an active admin, or when one of `PermissionService::rolesFor` roles is in `custom_fields_roles`. User and group fields have no project, so the role check uses roles across the actor's projects.

`editable = false` blocks value changes for non-admins. Admins can still write the field. A null `editable` column is treated as editable (the column default is true).

For an issue field with `visible = false`, workflow merge treats roles that are not in `custom_fields_roles` as `readonly`. If any visible role has no workflow row, the field stays unconstrained. If every role has a rule and one of them is `required`, the field is required. Active admins skip workflow rules. Required checks (`is_required` or a workflow `required` rule) apply only to actors who can see the field. Workflow rules use the issue status already stored, including the initial status on create.

## Intentional differences from Redmine 7.0.1

- `possible_values` and `format_store` are JSON, not a YAML or PHP serialization dump.
- Deferred formats can be defined and cannot store a non-blank value.
- Version fields ignore version sharing.
- User fields do not offer groups as selectable values.
- Text formatting and full-width layout keys are stored and not rendered.
- Custom field workflow errors are `CustomFieldValidationException`, not `WorkflowDeniedException`.
- `CustomValueService` does not authorize the host record. `IssueService` still requires `add_issues` or `edit_issues` / `edit_own_issues` before it writes issue values.
- The issue search controller is not implemented. `any_searchable` does a SQL `LIKE` over subject, description, visible journal notes, and visible `searchable` custom values. `is_filter` compiles `cf_{id}` for the formats listed above. Enumeration options in `custom_field_enumerations` are not used as a format yet. A version field also accepts `cf_N.due_date` and `cf_N.status`. Other chained suffixes are rejected. See [queries.md](queries.md).
- Document, issue-priority, time-entry activity, and document-category custom field types are not writable targets.
