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

Relative dates use `user_preferences.time_zone` when it is a valid zone, otherwise the application timezone. Weeks run Monday through Sunday. Datetime columns (`created_on`, `updated_on`, `closed_on`) compare that calendar range after it is converted into the application timezone.

## Shipped operators (18)

| Operator | Behavior |
| --- | --- |
| `=` | List, id, number, date, or exact text match. Several values are OR (`IN`). |
| `!` | Negation of `=`. Optional list columns also match NULL. Integer, float, and hour filters do not use `!`; blank numbers use `!*`. |
| `o` / `c` | Open or closed status (`issue_statuses.is_closed`). Values are ignored. |
| `*` / `!*` | Present, or NULL / blank. |
| `>=` / `<=` / `><` | Number or date. `><` is inclusive and needs two values. Date `=` on a datetime column is the whole calendar day. |
| `t` `ld` `w` `lw` `m` `lm` `y` | Today, yesterday, this week, last week, this month, last month, this year. |
| `~` / `!~` | Case-insensitive substring. Whitespace in a value splits into tokens that are AND-ed. `!~` is the negation (a blank value matches). |

### Core fields

| Field | Filter type | Shipped operators |
| --- | --- | --- |
| `status_id` | list_status | `o`, `c`, `=`, `!`, `*` |
| `project_id` | list | `=`, `!` |
| `tracker_id`, `priority_id` | list_with_history | `=`, `!` |
| `author_id` | list | `=`, `!` |
| `assigned_to_id`, `fixed_version_id`, `category_id` | list_optional_with_history | `=`, `!`, `!*`, `*` |
| `subject`, `description` | text | `~`, `!~`, `!*`, `*`, and exact `=` |
| `created_on`, `updated_on`, `closed_on` | date_past | `=`, `>=`, `<=`, `><`, `t`, `ld`, `w`, `lw`, `m`, `lm`, `y`, `!*`, `*` |
| `start_date`, `due_date` | date | same relative and comparison set |
| `estimated_hours` | hour | `=`, `>=`, `<=`, `><`, `!*`, `*` |
| `done_ratio`, `issue_id` | integer | `=`, `>=`, `<=`, `><`, `!*`, `*` (`issue_id` has no NULL) |
| `is_private` | list | `=`, `!` (`0`/`1`/`true`/`false`) |
| `parent_id`, `child_id` | tree | `=`, `!*` |
| `cf_{id}` | format's query filter type | shipped operators for that type, for string, text, int, float, date, list, bool, user, and version |

`child_id` `=` keeps issues whose child id is in the list. `!*` keeps issues that have no children.

Sort keys (`priority`, `status`, `tracker`, `assigned_to`, …) order by the issue column (`priority_id`, and so on), then `id` when no sort is stored. `group_by` adds a leading `ORDER BY` and does not collapse rows.

## Deferred operators (23)

`<t+`, `>t+`, `><t+`, `t+`, `nd`, `nw`, `nm`, `>t-`, `<t-`, `><t-`, `t-`, `l2w`, `*~`, `^`, `$`, `=p`, `=!p`, `!p`, `*o`, `!o`, `ev`, `!ev`, `cf`.

`ev`, `!ev`, and `cf` need journals. The `t+` / `t-` family and `*~` / `^` / `$` are relative-date and string completeness. Relation operators need issue relations. Tree `~` and `*` on `parent_id` / `child_id` are held back with that set.

Deferred fields: `author.group`, `author.role`, `member_of_group`, `assigned_to_role`, `fixed_version.due_date`, `fixed_version.status`, `project.status`, `subproject_id`, `notes`, `attachment`, `attachment_description`, `watcher_id`, `updated_by`, `last_updated_by`, `spent_time`, `any_searchable`, and the relation names `relates`, `blocks`, `blocked`, `duplicates`, `duplicated`, `precedes`, `follows`, `copied_to`, `copied_from`. Chained `cf_N.due_date` and `cf_N.status` are deferred. The assignee value `me` is deferred. Custom field formats `link`, `enumeration`, `attachment`, and `progressbar` are not filtered.

A deferred or unknown operator is rejected. It is not treated as "match everything".

## Intentional differences from Redmine 7.0.1

- Filter, column, sort, and option payloads are JSON. YAML is read-only compatibility.
- Text `~` splits on whitespace and AND-s those tokens as `LOWER(column) LIKE`. It does not implement quoted phrases. `=` on subject and description is exact equality under the database collation (MySQL's default collation is case-insensitive).
- Weeks are Monday–Sunday. There is no `start_of_week` setting.
- A saved query scoped to a project does not include subprojects.
- Sort uses the issue column (for example `priority_id`), not enumeration position or user name.
- `issues_visibility = all` includes other people's private issues. `default` hides them unless the user is the author or assignee.
- Active admins can read private saved queries.
- Execution returns full issue rows. `column_names` is a display list.
- `options` is not applied.
- Global queries check `view_issues` per project in PHP, then OR the visibility groups.
