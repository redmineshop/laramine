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

`column_names` null means the display default `tracker`, `status`, `priority`, `subject`, `assigned_to`, `updated_on`. The runner still returns full issue rows. `options` is stored and not interpreted (totals, display mode).

## Visibility

| `visibility` | Who can read |
| --- | --- |
| `0` private | Owner. An active admin can also read, update, and delete. |
| `1` roles | A logged-in user who can see the query project and has one role from `queries_roles` on that project. A global query matches those roles on any of the user's projects. |
| `2` public | A logged-in user who can see the project. A global public query is visible to every logged-in user. |

Creating a query requires `save_queries` on the project, or on any membership when `project_id` is null. The default visibility is private. Update and delete are limited to the owner or an active admin. Role rows are replaced when visibility is `1` and cleared otherwise.

## Running a query

`IssueQueryRunner` limits rows with `IssueVisibility` for a project-scoped query. A global query (`project_id` null) keeps issues only in projects where the actor has `view_issues`, using that project's `all` / `default` / `own` rule. The query's own `project_id` is that project only. Subprojects are not included.

Custom field filters use `cf_{id}`. The field must be an `IssueCustomField` with `is_filter` and a format this engine implements. A field the actor cannot see is an error, not a silent skip. `!`, `!~`, and `!*` are `NOT EXISTS` on `custom_values`, so a missing value matches "not equal" and "none". List `=` matches if any stored value is in the list.

Relative dates use `user_preferences.time_zone` when it is a valid zone, otherwise the application timezone. Weeks run Monday through Sunday. Datetime columns (`created_on`, `updated_on`, `closed_on`) compare that calendar range after it is converted into the application timezone. An open-ended operator keeps one side unbounded (`>=` the start of the day, or `<=` the end of the day).

Day offsets use a non-negative integer `N` and anchor date T. `nd` is T+1. `nw` is the next Monday–Sunday. `nm` is the next calendar month. `l2w` is the two calendar weeks before this week. `t+` / `t-` are exactly T±N. `<t+` is on or before T+N−1. `>t+` is on or after T+N+1. `><t+` is T+1 through T+N. `>t-` is T−N through T (today included). `<t-` is on or before T−N−1. `><t-` is T−N through T−1 (today excluded). An inverted window (`><t+` or `><t-` with N = 0) matches nothing. `date_past` does not accept the future-only operators (`<t+`, `>t+`, `><t+`, `t+`, `nd`, `nw`, `nm`).

The value `me` is the current user id. It is accepted on `author_id`, `assigned_to_id` (including `ev` / `!ev` / `cf`), and user custom fields. It is rejected on every other field. It is rejected when the actor is missing, inactive, anonymous, or a group. `me` does not expand to groups.

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
| `cf_{id}` | format's query filter type | shipped operators for that type, for string, text, int, float, date, list, bool, user, and version |
| `cf_{id}.due_date` | date | version custom fields only. Compared to `versions.effective_date` |
| `cf_{id}.status` | list | version custom fields only. `=` / `!` on `versions.status` |

`child_id` `=` keeps issues whose child id is in the list. `!*` keeps issues that have no children. `*` keeps issues that have a child. `~` matches a child subject (every token). `parent_id` `*` is a non-null parent. `parent_id` `~` matches the parent subject.

History reads `journal_details` with `property = attr` and `prop_key` equal to the issue column (`status_id`, `tracker_id`, `priority_id`, `assigned_to_id`, `fixed_version_id`, `category_id`). Values are decimal id strings. Custom-field history is not queried. The list, bool, user, and version custom field types do not offer `ev` / `!ev` / `cf`.

Relations are stored once. The canonical `relation_type` is `relates`, `blocks`, `duplicates`, `precedes`, or `copied_to` on `issue_from_id`. The reverse filter name (`blocked`, `duplicated`, `follows`, `copied_from`) matches that same row from `issue_to_id`. A filter also accepts the reverse type if a row was stored that way. `=` / `!` compare the other issue id. `=p` / `=!p` / `!p` compare the other issue's project. `*o` / `!o` use `issue_statuses.is_closed`. Related issues are not re-checked against issue visibility.

`cf_N.due_date` and `cf_N.status` require an `IssueCustomField` of format `version` with `is_filter`, visible to the actor. The custom value is the version id. `!*` on `cf_N.due_date` matches a missing value and a version whose `effective_date` is null. Other `cf_N.*` suffixes are rejected.

Sort keys (`priority`, `status`, `tracker`, `assigned_to`, …) order by the issue column (`priority_id`, and so on), then `id` when no sort is stored. `group_by` adds a leading `ORDER BY` and does not collapse rows.

## Deferred operators

None. The catalog operators above are compiled. `!` on integer, float, and hour is not a deferred operator: that catalog does not list it, so it is invalid. Blank numbers use `!*`.

## Deferred fields

These names are rejected. They are not treated as "match everything".

| Field | Why it stays deferred |
| --- | --- |
| `author.group`, `author.role`, `member_of_group`, `assigned_to_role` | Group and role membership. `me` does not expand to the actor's groups. |
| `fixed_version.due_date`, `fixed_version.status` | The core target-version chain. Version custom fields use `cf_N.due_date` and `cf_N.status` instead. |
| `project.status` | Project status is outside the issue row this engine filters. |
| `subproject_id` | A project-scoped query stays on that project. Descendants are not included. |
| `notes` | Journal note text. History operators read `journal_details`, not note bodies. |
| `attachment`, `attachment_description` | Attachment rows are not joined. |
| `watcher_id`, `updated_by`, `last_updated_by` | Watchers and journal authors are not filters. |
| `spent_time` | Time-entry aggregate. `TimeEntryQuery` is still a stub. |
| `any_searchable` | No issue search index. |
| `cf_N` other than `.due_date` and `.status` | Only those two version chains are defined. |
| Custom formats `link`, `enumeration`, `attachment`, `progressbar` | The format itself is not implemented, so it cannot be filtered. |

An unknown operator or an unknown field is rejected.

## Intentional differences from Redmine 7.0.1

- Filter, column, sort, and option payloads are JSON. YAML is read-only compatibility.
- Text `~` splits on whitespace and AND-s those tokens as `LOWER(column) LIKE`. It does not implement quoted phrases. `*~` ORs the same tokens. `^` and `$` do not split on spaces. `=` on subject and description is exact equality under the database collation (MySQL's default collation is case-insensitive).
- Day-offset windows follow the readings in this document (T+N−1, next Monday, and so on). That is the Laramine reading of the operator labels.
- Relation rows are matched from either end using the canonical type on `issue_from_id`. Related issues are not passed through `IssueVisibility`.
- `ev` / `cf` compare decimal id strings on `journal_details.old_value` and `value` for `property = attr` only.
- Weeks are Monday–Sunday. There is no `start_of_week` setting.
- A saved query scoped to a project does not include subprojects.
- Sort uses the issue column (for example `priority_id`), not enumeration position or user name.
- `issues_visibility = all` includes other people's private issues. `default` hides them unless the user is the author or assignee.
- Active admins can read private saved queries.
- Execution returns full issue rows. `column_names` is a display list.
- `options` is not applied.
- Global queries check `view_issues` per project in PHP, then OR the visibility groups.
