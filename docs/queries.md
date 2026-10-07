# Saved queries and issue filters

Laramine stores saved queries in the Redmine 7.0.1 `queries` and `queries_roles` tables. This is not a Redmine parity claim. The HTTP API and the filter form are not part of this slice. `IssueQuery` is the only type that runs. `ProjectQuery`, `TimeEntryQuery`, `UserQuery`, and `ProjectAdminQuery` can be stored with empty filters and are not executed.

## JSON instead of YAML

Redmine 7.0.1 writes ActiveRecord YAML into `filters`, `column_names`, `sort_criteria`, and `options`. Laramine writes JSON in those same text columns. A legacy YAML value is accepted on read. The next save rewrites it as JSON. Symbol keys and symbol list items (`:operator`, `:tracker`) drop the leading colon. The empty document `--- {}` reads as no filters.

Redmine filter map:

```yaml
status_id:
  :values: []
  :operator: o
cf_1:
  :values:
    - MySQL
  :operator: "="
```

Laramine JSON (this is what `queries.filters` stores):

```json
{
  "status_id": { "operator": "o", "values": [] },
  "cf_1": { "operator": "=", "values": ["MySQL"] }
}
```

`sort_criteria` example: `[["priority","desc"],["id","asc"]]`.

An in-memory list is also accepted at the service edge and stored back as the map:

```json
[{ "field": "status_id", "op": "o", "values": [] }]
```

Filters are AND-ed. There is no OR group.

`column_names` null means the display default `tracker`, `status`, `priority`, `subject`, `assigned_to`, `updated_on`. The runner still returns full issue rows. `options.totalable_names` is summed over that set (see below). `options.display_type` is stored and not interpreted.

## Visibility

| `visibility` | Who can read |
| --- | --- |
| `0` private | Owner. An active admin can also read, update, and delete. |
| `1` roles | A logged-in user who can see the query project and has one role from `queries_roles` on that project. A global query matches those roles on any of the user's projects. |
| `2` public | A logged-in user who can see the project. A global public query is visible to every logged-in user. |

Creating a query requires `save_queries` on the project, or on any membership when `project_id` is null. The default visibility is private. Update and delete are limited to the owner or an active admin. Role rows are replaced when visibility is `1` and cleared otherwise.

## Running a query

`IssueQueryRunner` limits rows with `IssueVisibility` for a project-scoped query. A global query (`project_id` null) keeps issues only in projects where the actor has `view_issues`, using that project's `all` / `default` / `own` rule. With no `subproject_id` filter, `display_subprojects_issues` (default on, stored in `settings`) includes every descendant. Set that value to `0` to keep the query project alone. An explicit `subproject_id` filter overrides the setting, and each project is still checked with `view_issues`.

Custom field filters use `cf_{id}`. The field must be an `IssueCustomField` with `is_filter` and a format this engine implements. A field the actor cannot see is an error, not a silent skip. `!`, `!~`, and `!*` are `NOT EXISTS` on `custom_values`, so a missing value matches "not equal" and "none". List `=` matches if any stored value is in the list. Link and attachment use the string operators on the stored text (an attachment value is the id). Enumeration uses the list operators on enumeration ids. Progress bar uses the integer operators.

Relative dates use `user_preferences.time_zone` when it is a valid zone, otherwise the application timezone. Weeks run Monday through Sunday. Datetime columns (`created_on`, `updated_on`, `closed_on`) compare that calendar range after it is converted into the application timezone. An open-ended operator keeps one side unbounded (`>=` the start of the day, or `<=` the end of the day).

Day offsets use a non-negative integer `N` and anchor date T, matching Redmine `Query#relative_date_clause`. `nd` is T+1. `nw` is the next Monday–Sunday. `nm` is the next calendar month. `l2w` is the two calendar weeks before this week. `t+` / `t-` are exactly T±N. `<t+` is on or before T+N. `>t+` is on or after T+N. `><t+` is T through T+N. `>t-` is on or after T−N and stays open into the future. `<t-` is on or before T−N. `><t-` is T−N through T. N = 0 is today. `date_past` does not accept the future-only operators (`<t+`, `>t+`, `><t+`, `t+`, `nd`, `nw`, `nm`). `updated_on` `*` means `updated_on` is later than `created_on`. `updated_on` `!*` means the two timestamps are equal.

The value `me` is the current user id. It is accepted on `author_id`, `assigned_to_id` (including `ev` / `!ev` / `cf`), user custom fields, `watcher_id`, `updated_by`, and `last_updated_by`. It is rejected on every other field, and when the actor is missing, inactive, anonymous, or a group. On `assigned_to_id` and `watcher_id`, `me` also includes the groups that user belongs to. On `author_id`, `updated_by`, `last_updated_by`, and user custom fields it is only that user id.

## Shipped operators (41)

| Operator | Behavior |
| --- | --- |
| `=` | List, id, number, date, or exact text match. Several values are OR (`IN`). |
| `!` | Negation of `=`. Optional list columns also match NULL. Integer, float, and hour filters do not use `!`; blank numbers use `!*`. |
| `o` / `c` | Open or closed status (`issue_statuses.is_closed`). Values are ignored. |
| `*` / `!*` | Present, or NULL / blank. On a relation, any relation of that type, or none. |
| `>=` / `<=` / `><` | Number or date. `><` is inclusive and needs two values. Date `=` on a datetime column is the whole calendar day. |
| `t` `ld` `w` `lw` `m` `lm` `y` | Today, yesterday, this week, last week, this month, last month, this year. |
| `<t+` `>t+` `><t+` `t+` `nd` `nw` `nm` | Future day offsets and next day / week / month. See the readings above. |
| `>t-` `<t-` `><t-` `t-` `l2w` | Past day offsets and the two weeks before this week. |
| `~` / `!~` | Case-insensitive substring. Whitespace in a value splits into tokens that are AND-ed. `!~` is the negation of `~` (a blank value matches). |
| `*~` | Contains any token. Whitespace splits tokens, then they are OR-ed. |
| `^` / `$` | Starts with or ends with the whole value. Spaces are not split. Several values are OR. |
| `=p` / `=!p` / `!p` | Relation whose other issue is in those projects, whose other issue is outside those projects, or that has no other issue in those projects. |
| `*o` / `!o` | Relation whose other issue is open, or that has no open other issue. Values are ignored. |
| `ev` / `!ev` / `cf` | `ev` matches the current value or a journal `old_value` / `value`. `!ev` matches neither. `cf` matches a journal `old_value` only. |

### Core fields

| Field | Filter type | Shipped operators |
| --- | --- | --- |
| `status_id` | list_status | `o`, `c`, `=`, `!`, `*`, `ev`, `!ev`, `cf` |
| `project_id` | list | `=`, `!` |
| `tracker_id`, `priority_id` | list_with_history | `=`, `!`, `ev`, `!ev`, `cf` |
| `author_id` | list | `=`, `!`, and `me` |
| `assigned_to_id`, `fixed_version_id`, `category_id` | list_optional_with_history | `=`, `!`, `!*`, `*`, `ev`, `!ev`, `cf`. `me` is only for `assigned_to_id` |
| `subject`, `description` | text | `~`, `*~`, `!~`, `^`, `$`, `!*`, `*`, and exact `=` |
| `created_on`, `updated_on`, `closed_on` | date_past | `=`, `>=`, `<=`, `><`, `>t-`, `<t-`, `><t-`, `t-`, `t`, `ld`, `w`, `lw`, `l2w`, `m`, `lm`, `y`, `!*`, `*` |
| `start_date`, `due_date` | date | the full date set, including `<t+`, `>t+`, `><t+`, `t+`, `nd`, `nw`, `nm` |
| `estimated_hours` | hour | `=`, `>=`, `<=`, `><`, `!*`, `*` |
| `done_ratio`, `issue_id` | integer | `=`, `>=`, `<=`, `><`, `!*`, `*` (`issue_id` has no NULL) |
| `is_private` | list | `=`, `!` (`0`/`1`/`true`/`false`) |
| `parent_id`, `child_id` | tree | `=`, `~`, `!*`, `*` |
| `relates`, `blocks`, `blocked`, `duplicates`, `duplicated`, `precedes`, `follows`, `copied_to`, `copied_from` | relation | `=`, `!`, `=p`, `=!p`, `!p`, `*o`, `!o`, `!*`, `*` |
| `cf_{id}` | format's query filter type | shipped operators for that type. `link` and `attachment` use `string` (the stored text, which for attachment is the id). `enumeration` uses `list_optional` on enumeration ids. `progressbar` uses `integer`. The same path covers string, text, int, float, date, list, bool, user, and version |
| `cf_{id}.due_date` | date | version custom fields only. Compared to `versions.effective_date` |
| `cf_{id}.status` | list | version custom fields only. `=` / `!` on `versions.status` |
| `author.group` | list | `=`, `!`. Author is a member of those groups, or the group id itself |
| `author.role` | list | `=`, `!`. Author has that role on the issue's project |
| `member_of_group` | list_optional | Assignee is one of those groups, or a user in them |
| `assigned_to_role` | list_optional | Assignee has that role on the issue's project. `!*` is no such role, including a blank assignee |
| `fixed_version.due_date` | date | `versions.effective_date` for `issues.fixed_version_id`. `!*` is no version or a null date |
| `fixed_version.status` | list | `=` / `!` on `versions.status`. `!` includes issues with no version |
| `project.status` | list | `=` / `!` on `projects.status` (`1` active, `5` closed, `9` archived) |
| `subproject_id` | list_subprojects | See below |
| `notes` | text | Journal `notes`. Private notes are skipped unless the actor has `view_private_notes` on that project (admins see them) |
| `attachment` | text | `attachments.filename`. `*` is any attachment row. `!*` is no attachment |
| `attachment_description` | text | `!*` is an attachment with a blank description. `!~` is a non-blank description that does not contain the tokens |
| `watcher_id` | list | `=`, `!` on `watchers`. `me` includes the actor's groups. Other users require `view_issue_watchers` |
| `updated_by` | list | `=`, `!` on a visible journal `user_id`. Private notes are skipped without `view_private_notes`. `me` is the user id only |
| `last_updated_by` | list | `user_id` of the latest visible journal (`id` descending). No visible journal matches `!` |
| `spent_time` | hour | `COALESCE(ROUND(SUM(time_entries.hours), 2), 0)`. `*` is greater than 0. `!*` and `=` 0 include a missing sum |
| `any_searchable` | search | `~`, `*~`, `!~` across subject, description, visible journal notes, and visible searchable issue custom fields |

`parent_id` and `child_id` read the issue nested set (`root_id`, `lft`, `rgt`). `=` and `~` scan decimal ids out of the value; a value with no digits matches nothing. `parent_id` `=` is `parent_id` in those ids. `parent_id` `~` is a strict descendant of any of those issues. `parent_id` `*` / `!*` is a non-null parent, or none. `child_id` `=` keeps the direct parent of those child ids. `child_id` `~` keeps ancestors of the first id. `child_id` `*` is `rgt - lft > 1`. `child_id` `!*` is a leaf (`rgt - lft = 1`).

History reads `journal_details` with `property = attr` and `prop_key` equal to the issue column (`status_id`, `tracker_id`, `priority_id`, `assigned_to_id`, `fixed_version_id`, `category_id`). Values are decimal id strings. Issue update writes `status_id` and the other tracked columns listed in [domain.md](domain.md) in that shape. `done_ratio` is stored as an integer string and is not one of these operator columns. Relation-add rows use `property = relation` and are not read here. A private journal is skipped unless the actor has `view_private_notes` on that project (admins see them), the same rule as `updated_by`. Custom-field history is not queried. The list, bool, user, and version custom field types do not offer `ev` / `!ev` / `cf`.

Relations are stored once. The canonical `relation_type` is `relates`, `blocks`, `duplicates`, `precedes`, or `copied_to` on `issue_from_id`. The reverse filter name (`blocked`, `duplicated`, `follows`, `copied_from`) matches that same row from `issue_to_id`. A filter also accepts the reverse type if a row was stored that way. `=` / `!` compare the other issue id. `=p` / `=!p` / `!p` compare the other issue's project. `*o` / `!o` use `issue_statuses.is_closed`. Related issues are not re-checked against issue visibility.

`cf_N.due_date` and `cf_N.status` require an `IssueCustomField` of format `version` with `is_filter`, visible to the actor. The custom value is the version id. `!*` on `cf_N.due_date` matches a missing value and a version whose `effective_date` is null. The same date and status comparisons apply to `fixed_version.due_date` and `fixed_version.status` through `issues.fixed_version_id`. Shipped formats do not define any other `cf_N.*` chain, so those suffixes are rejected.

`subproject_id` changes which projects a scoped query reads. With no filter, descendants are included when `display_subprojects_issues` is on (the Redmine default) and omitted when it is `0`. `*` adds every descendant. `!*` is the query project only. `=` adds listed descendants and ignores ids that are not descendants. `!` adds every descendant except the listed ones. The query project itself stays in the set. Issues in a descendant are still dropped when the actor lacks `view_issues` there. On a global query, `*` adds no extra constraint, `!*` keeps projects with a null `parent_id`, and `=` / `!` compare `issues.project_id`.

`any_searchable` is a SQL `LIKE` over `issues.subject`, `issues.description`, visible journal notes, and `custom_values` for issue custom fields that are `searchable`, implemented, visible to the actor, and whose format supports search. Link, enumeration, attachment, and progress bar do not support search, so a `searchable` column on those rows is ignored. Private notes follow the same rule as the `notes` filter. It does not search attachment filenames. `~` requires every token to appear in at least one of those places. `*~` requires any token. `!~` is the negation of `~`. A hidden custom field is not searched.

Sort keys (`priority`, `status`, `tracker`, `assigned_to`, …) order by the issue column (`priority_id`, and so on), then `id` when no sort is stored. `group_by` adds a leading `ORDER BY` and does not collapse rows.

## Totals

`IssueQueryRunner::totals` reads `options.totalable_names` and sums those columns over the same issues `execute` would return. Sort is ignored. An omitted key or an empty list returns an empty map. A missing query project returns zeros for the requested names. Visibility is the same check as `execute`: a query the actor cannot see is an error.

| Name | Sum |
| --- | --- |
| `estimated_hours` | `SUM` of `issues.estimated_hours`. Null estimates add nothing. The sum is rounded to 2 decimal places. An empty set is `0`. |
| `spent_hours` | Each issue contributes the same number the `spent_time` filter compares: `COALESCE(ROUND(SUM(time_entries.hours), 2), 0)`. The query total adds those per-issue numbers. Hours on issues outside the result set are not included. `time_entries_visibility` is not applied. |
| `cf_{id}` | An `IssueCustomField` the actor can see, whose format reports `supportsTotal` (int and float). `is_filter` is not required. The name does not have to be listed in `column_names`. Int values matching an optional sign and digits are summed as whole numbers. Float values matching a decimal token are summed as decimals. Blank text and any other stored text are skipped. Every matching `custom_values` row is added, including more than one row on the same issue. |

`spent_time` is the filter name, not a total name. Progress bar, list, and the other formats that do not report `supportsTotal` are rejected. An unknown name, a repeated name, a non-list `totalable_names`, a hidden field, or a custom field that is not an `IssueCustomField` is rejected. Saving an `IssueQuery` runs the same check. Other query types still store `options` and do not run, so their `totalable_names` are not checked.

A public query that names a hidden field can be saved by an admin. Another user who can open that query still cannot total the field.

## Deferred operators

None. The catalog operators above are compiled. `!` on integer, float, and hour is not a deferred operator: that catalog does not list it, so it is invalid. Blank numbers use `!*`.

## Deferred fields

These names are rejected. They are not treated as "match everything".

| Field | Why it stays deferred |
| --- | --- |
| `cf_N.*` other than `.due_date` and `.status` | Version custom fields define only those two chains. Link, enumeration, attachment, progress bar, and the other shipped formats do not define a chain. |

An unknown operator or an unknown field is rejected.

## Intentional differences from Redmine 7.0.1

- Filter, column, sort, and option payloads are JSON. YAML is read-only compatibility.
- Text `~` tokenizes like Redmine search: a double-quoted phrase is one token, other whitespace splits tokens, tokens are AND-ed as `LOWER(column) LIKE`. A token shorter than two characters is dropped unless it contains a Han character, and at most five tokens are used. `*~` ORs those tokens. `^` and `$` do not split on spaces. `=` on subject and description is exact equality under the database collation (MySQL's default collation is case-insensitive).
- Day-offset windows follow Redmine `relative_date_clause` (inclusive T±N, `>t-` open into the future). Weeks stay Monday–Sunday because there is no `start_of_week` setting.
- Relation rows are matched from either end using the canonical type on `issue_from_id`. Related issues are not passed through `IssueVisibility`.
- `ev` / `cf` compare decimal id strings on `journal_details.old_value` and `value` for `property = attr` only. A private journal is hidden without `view_private_notes`, including from its author. Redmine also lets that author read their own private note.
- `any_searchable` does not search attachment filenames. Those have their own filter. It uses the same quoted-phrase tokens as text `~`.
- `me` expands to the actor's groups only on `assigned_to_id` and `watcher_id`. Group filters still take group ids.
- Sort uses the issue column (for example `priority_id`), not enumeration position or user name.
- `issues_visibility = all` includes other people's private issues. `default` hides them unless the user is the author or assignee.
- Active admins can read private saved queries.
- Execution returns full issue rows. `column_names` is a display list.
- `options.display_type` is stored and not applied. `totalable_names` is summed even when those names are absent from `column_names`.
- `spent_hours` totals each issue's own time entries. It does not roll descendant time into a parent that is outside the result, and it does not read `time_entries_visibility`.
- Global queries check `view_issues` per project in PHP, then OR the visibility groups.

## Still open

These are Laramine gaps. They are not a parity verdict.

| Item | Why it stays open |
| --- | --- |
| `display_type` | Stored (`list`, `board`, and any other string). Nothing branches on it. |
| Sort by position or name | `priority`, `status`, and `tracker` order by the foreign key. `assigned_to` and `author` order by user id. Custom fields are not sort keys. |
| Column projection | `column_names` is a display list. Unknown names that match the token pattern are stored. The runner returns full issue rows. |
| `cf_N.*` other than `.due_date` and `.status` | Listed under deferred fields. |
| Custom-field history | `ev` / `!ev` / `cf` are not compiled for custom fields. |
| Descendant hour and estimate columns | `total_estimated_hours` and `total_spent_hours` are not totalable names. |
| Other query types | `ProjectQuery`, `TimeEntryQuery`, `UserQuery`, and `ProjectAdminQuery` still do not run. |
