# Custom fields

Laramine stores custom fields in the Redmine 7.0.1 tables (`custom_fields`, `custom_values`, `custom_fields_trackers`, `custom_fields_projects`, `custom_fields_roles`, `custom_field_enumerations`). The custom fields checklist row is **VERIFIED** only by `tests/Parity/CustomFieldParityTest.php` against the shared pin. That comparison is not a 0.1 tag. Issue update writes custom-value diffs. HTTP serves attachment download, attachment delete, and link URL resolution. Core issue journals are described in [domain.md](domain.md). Issue query filters for `is_filter` fields are described in [queries.md](queries.md).

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

Multiple values are one `custom_values` row per entry. A blank value deletes the rows for that field. Bool values are the strings `1` and `0`. User, version, enumeration, and attachment values are id strings. Progress bar values are integer strings from `0` to `100`. Dates are `YYYY-MM-DD`. Link values are strings.

## Formats

| `field_format` | Values | Notes |
| --- | --- | --- |
| `string`, `text` | One string | Optional regexp, min length, max length. Searchable flag allowed. `text_formatting` is stored and not rendered. |
| `int` | `/^[+-]?\d+$/`, stored without a leading `+` | Must fit in a PHP integer. |
| `float` | Decimal text, no exponent | The trimmed text is stored. |
| `date` | `YYYY-MM-DD` | `format_store.default_value_mode` is `fixed_date` or `date_offset` (days from today). |
| `link` | One string | Same regexp, min length, and max length as `string`. `format_store.url_pattern` is optional. After substitution tokens are removed, the pattern must be empty, a single-slash or `?` / `#` path, or an absolute URL whose scheme is `http`, `https`, `ftp`, or `mailto`. `LinkFormat::formattedUrl` replaces `%value%`, `%id%`, `%project_id%`, `%project_identifier%`, and `%mN%` (regexp capture `N`; `%m0%` is the whole match). Each replacement is percent-encoded: bytes outside the RFC 3986 unreserved and reserved sets become `%HH`, so a space is `%20` and `:` `/` stay. The rest of the pattern is copied. `outboundUrl` is that formatted URL when a pattern is stored. Otherwise a value that already starts with `scheme://` is kept, and every other value, including `mailto:`, is prefixed with `http://`. `GET /custom-fields/links/{custom_values.id}` returns the stored string and that outbound URL for an actor who can see the host and the field. The server does not request the URL. Not searchable and not multiple. |
| `list` | One or many entries from `possible_values` | `multiple` is allowed, together with `user`, `version`, and `enumeration`. |
| `bool` | `1` or `0` | `true` / `false` / `1` / `0` are accepted. `yes` and `true` text are rejected. |
| `enumeration` | One or many active ids from `custom_field_enumerations` | The id belongs to this field and `active` is true. `possible_values` is not the option list. `CustomFieldEnumerationService` inserts options, renames them, reorders them, activates or deactivates them, and deletes them. Names are unique per field. Reorder must list every option. Deactivating or deleting the option stored in `default_value` is rejected. Rename and reorder do not rewrite `custom_values`. Deleting an unused option removes that row and leaves the other positions as stored. Deleting an option that `custom_values` for this field still store requires a different option of the same field, which may be inactive. Those rows are rewritten to the replacement id. If that record already stores the replacement, the old row is removed so the id is not stored twice. A value on another custom field is left unchanged. The caller authorizes the delete. Not searchable. |
| `user` | Active user id | On a project record (issue, time entry, version, project) the user must be a member. `format_store.user_role` limits roles. |
| `version` | Version id available on the record's project | The version's own project can always select it. `sharing = descendants` also allows descendant projects. `hierarchy` also allows ancestors and descendants. `tree` also allows projects under the same root. `system` allows every project. An unknown sharing value is not shared. `format_store.version_status` may list `open`, `locked`, `closed`. |
| `attachment` | One `attachments.id` | `format_store.extensions_allowed` is a comma-separated string or a list of extensions such as `pdf` or `.PNG`. The last filename segment is compared, case-insensitive. `AttachmentService::store` writes the bytes on the local `attachments` disk, sets `digest` to SHA-256 hex, and sets `disk_directory` to `YYYY/MM`. `disk_filename` is `yymmddHHMMSS_` plus the original name when that name is at most 50 characters of ASCII letters, digits, `_`, `.`, and `-`. Otherwise the suffix is the SHA-256 hex of the filename. A trailing `.ext` of ASCII alphanumerics is kept only when that suffix is still at most 50 characters. A colliding name increments the timestamp. A row with an empty container is accepted by validation. `CustomValueService` binds that row to the customized record when the value is saved. A row that already names another container is rejected. Clearing the value through a custom-value write does not delete the file or clear the container, and the file is no longer served. `GET /custom-fields/attachments/{attachments.id}` sends the bytes when a current attachment value points at that row, the container matches, and the actor can see the host and the field. Each successful download increments `downloads`. `DELETE /custom-fields/attachments/{attachments.id}` removes that custom value, the attachment row, and the file when the actor can edit the host and the field. An issue host also writes a `cf` journal detail. A missing file is not served. Journal and issue attachments that are not a current custom value are not deleted by this route. Not searchable and not multiple. |
| `progressbar` | Integer `0`–`100` | `format_store.ratio_interval`, when set, is a positive integer that divides 100. The value must be a multiple of that step. Not searchable, not multiple, and not totalable. |

`multiple` is rejected on formats that do not support it. `searchable` is rejected the same way. `is_filter` marks an issue custom field as usable in an IssueQuery filter. Every registered format compiles `cf_{id}` filters. See [queries.md](queries.md). Int and float report `supportsTotal`, and IssueQuery sums `cf_{id}` when `options.totalable_names` lists that field. The other formats, including progress bar, do not. A total does not require `is_filter`.

Enumeration option rows are written by `CustomFieldEnumerationService`, not by `CustomFieldService::save`. Attachment bytes are written by `AttachmentService` on the `attachments` filesystem disk (`storage/app/attachments`). The caller of either service is responsible for authorization. The pin comparison and the limits of that comparison are listed in [custom-fields-deferred-parity-gate.md](custom-fields-deferred-parity-gate.md).

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

For an issue field with `visible = false`, workflow merge treats roles that are not in `custom_fields_roles` as `readonly`. Roles that do not grant `add_issues`, `edit_issues`, or `edit_own_issues` are left out. If any remaining role has no workflow row, the field stays unconstrained. If every remaining role has a rule and one of them is `required`, the field is required. Two rows for one role resolve to `required` when either row is `required`. Active admins skip workflow rules. Required checks (`is_required` or a workflow `required` rule) apply only to actors who can see the field. Workflow rules use the issue status already stored, including the initial status on create.

## HTTP reads

`CustomFieldAssetAccess` authorizes both routes. The session user is the actor. No session is a guest, which uses the Anonymous role. These routes do not sign anyone in.

| Route | Behavior |
| --- | --- |
| `GET /custom-fields/attachments/{id}` | Sends the file when `{id}` is the current value of an applicable attachment field and the attachment container is that record. |
| `DELETE /custom-fields/attachments/{id}` | Removes that custom value, the attachment row, and the file when the actor can edit the host and the field. An issue host writes one `cf` journal detail. A view-only actor is refused. |
| `GET /custom-fields/links/{id}` | JSON `value` and `url` for a link custom value. `url` is the outbound URL: the formatted pattern, the stored `scheme://` value, or `http://` plus a value that has no such prefix. |

The host check matches the record:

| Host | Who can read |
| --- | --- |
| Issue | `IssueVisibility` (`view_issues` and `issues_visibility`) |
| Project | `view_project` |
| Version | `view_project` on the version's project |
| Time entry | `view_time_entries` and `time_entries_visibility` |
| User | That user, or an active admin |
| Group | An active admin |

A hidden field is refused with `PermissionDeniedException` and the token `custom_field`. That token is not a name in `PermissionCatalog`. Active admins still see the field. `users_visibility` is not read. A denial is HTTP 403 JSON. A value that is not an attachment or link, a cleared attachment value, and a missing file are HTTP 404 JSON.

## Intentional differences from Redmine 7.0.1

- `possible_values` and `format_store` are JSON, not a YAML or PHP serialization dump.
- Link filters compare the stored string. `formattedUrl` encodes substituted tokens. `outboundUrl` prefixes `http://` when the stored value has no `scheme://` and no pattern, so `mailto:` without a pattern becomes `http://mailto:…`. The link route returns that URL and does not request it, so this process is not an open proxy.
- Enumeration filters compare ids, not option names. Inactive ids are rejected on write. An omitted field keeps a previously stored inactive id. Deactivating or deleting the option stored in `default_value` is rejected, so a later create does not apply a missing id. A replacement that the same record already stores drops the old row instead of saving that id twice. Positions of the options that remain are not renumbered.
- `ratio_interval` must divide 100 so `0` and `100` stay on the scale.
- Attachment files use the local `attachments` disk. `disk_directory` is `YYYY/MM`. A non-ASCII or over-long disk token is SHA-256 hex. HTTP download serves a file only while a custom value still stores its id. Clearing the custom value through a value write leaves the row and the file in place and stops serving it. `DELETE` removes the custom value, the row, and the file. Filename search stays on the core `attachment` filter, which reads `attachments.filename`, not this custom value. Journal and issue attachments are not served or deleted by this route.
- Issue update writes one `journal_details` row per changed custom field, after the tracked attribute rows. `property` is `cf`. `prop_key` is the custom field id. Multiple stored values are joined with a comma in `custom_values.id` order, and a value that itself contains a comma is not escaped. A cleared field stores `value` null. Create does not write these rows. History lines label each `cf` detail with the custom field name and format the old and new values. A hidden or missing field is omitted. List-like issue query filters still read the stored `cf` strings for `ev`, `!ev`, and `cf`. See [domain.md](domain.md) and [queries.md](queries.md).
- Int and float are totalable. Progress bar is not. IssueQuery sums the totalable formats; see [queries.md](queries.md).
- Version custom fields follow `versions.sharing` as described above. Issue `fixed_version_id` is unchanged by that rule.
- User fields do not offer groups as selectable values. User and group custom-field files follow the host table above. `users_visibility` is not applied to these values. Issue assignees use `UserVisibility`.
- Text formatting and full-width layout keys are stored and not rendered.
- Custom field workflow errors are `CustomFieldValidationException`, not `WorkflowDeniedException`.
- `CustomValueService` does not authorize the host record. `IssueService` still requires `add_issues` or `edit_issues` / `edit_own_issues` before it writes issue values.
- The issue search controller is not implemented. `any_searchable` does a SQL `LIKE` over subject, description, visible journal notes, and visible custom values whose format supports `searchable`. Link, enumeration, attachment, and progress bar do not. A `searchable` flag written outside `CustomFieldService::save` is still skipped for those formats. `is_filter` compiles `cf_{id}` for every registered format. A version field also accepts `cf_N.due_date` and `cf_N.status`. Other chained suffixes are rejected. See [queries.md](queries.md).
- Document, issue-priority, time-entry activity, and document-category custom field types are not writable targets.
