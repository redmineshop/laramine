# Domain slice (projects, membership, issues)

Laramine services in `app/Domain` maintain the P0 tables. This is not a Redmine parity claim. The HTTP API is not part of this slice.

## Projects

`ProjectService` creates and moves the project nested set (`parent_id`, `lft`, `rgt`). A move appends the subtree as the last child of the new parent, or as a new root when the parent is null. Moving a project under itself or under a descendant is rejected and rolled back.

`enableModule` / `disableModule` use `enabled_modules.name`. Names are the ten module keys in `PermissionCatalog::ENABLED_MODULES`. The `project` permission bucket is not a module row.

`attachTracker` writes `projects_trackers`. A tracker can be used on a project only when that row exists.

## Permissions

`roles.permissions` stays a text column. Writes through the `Role` model store a JSON array of permission name strings:

```json
["view_issues", "add_issues"]
```

Reads also accept a Redmine YAML symbol list (`- :view_issues`) so an ETL load can be checked before it is rewritten as JSON. Empty or unrecognized text grants nothing beyond public permissions.

`PermissionCatalog` holds the 80 Redmine 7.0.1 names, their module, and the flags `public`, `read`, `require=loggedin`, and `require=member`. `PermissionService::allowed($user, 'view_issues', $project)` is the check. The same strings are registered as Gates. `ProjectPolicy` and `IssuePolicy` cover view, update, and delete.

Evaluation order:

1. Unknown names throw.
2. An active admin (`users.admin` and `users.status = 1`) is allowed.
3. Modular permissions require that module on the project.
4. `require=loggedin` and `require=member` are enforced even if the role lists the name. Anonymous cannot use logged-in or member permissions. A non-member cannot use member permissions.
5. Public names (`view_project`, `search_project`, `view_members`) are implied when the project is visible.
6. Otherwise the name must appear on an applicable role.

Applicable roles are memberships of the user and of the user's groups. With no membership, a visible public project uses the Non member role (`builtin = 1`) for a logged-in user and the Anonymous role (`builtin = 2`) for a guest. Builtin roles are not assigned through `members`.

`issues_visibility` filters issue lists:

| Value | Effect |
| --- | --- |
| `all` | Every issue in the project, including private ones |
| `default` | Non-private issues, plus private issues the user authored or is assigned (including via a group) |
| `own` | Only issues the user authored or is assigned |

Several roles use the most open value. This follows the usual Redmine `Issue.visible` split between `all` and `default`.

## Workflow and issues

`IssueService::create` and `update` require `add_issues`, or `edit_issues` / `edit_own_issues`. Parent changes also require `manage_subtasks`. Subtasks stay in the same project. Each issue tree uses `parent_id`, `root_id`, `lft`, and `rgt`, with the root at `lft = 1`.

Status changes read `workflows` rows with `type = WorkflowTransition` for the user's roles and the tracker. A new issue uses `old_status_id = 0`. Rows with `author = 0` and `assignee = 0` always apply. `author = 1` also applies to the issue author. `assignee = 1` also applies to the assignee or to a member of an assignee group. The assignee considered for that flag is the assignee already stored on the issue, not a new assignee sent in the same update. If no initial row matches, the tracker's default status is allowed. Admins skip the matrix. Saving without a status change does not need a self-transition row.

Field rules (`type = WorkflowPermission`, `rule = readonly|required`) are enforced for the disablable core fields on create and update. On create they are read for the initial status id. Custom field ids are enforced by `CustomValueService` when values are written; see [custom-fields.md](custom-fields.md).

## Seed

`php artisan db:seed` runs `DefaultAccessSeeder` before the sample user:

| Role | `builtin` | Permissions |
| --- | --- | --- |
| Non member | 1 | none (public names still apply), `issues_visibility = default` |
| Anonymous | 2 | none, `issues_visibility = default` |
| Manager | 0 | every `project`, `issue_tracking`, and `time_tracking` name, visibility `all` |
| Developer | 0 | `view_issues`, `add_issues`, `edit_issues`, `edit_own_issues`, `manage_subtasks`, `add_issue_notes`, `view_time_entries`, `log_time`, `save_queries` |
| Reporter | 0 | `view_issues`, `add_issues`, `add_issue_notes` |

No workflow matrix is seeded, because statuses and trackers are not created by the seeder.

## Intentional differences from Redmine 7.0.1

- Permission storage is JSON text, not a YAML dump of symbols. YAML symbol lists are accepted on read only.
- `issues_visibility = all` includes other people's private issues. `default` is the mode that hides them.
- Same-status saves do not require a workflow row that points at the current status.
- Closing and reopening blockers (relations, open subtasks, a closed parent) are not applied.
- `roles.settings` tracker masks are stored when they are JSON and are not applied.
- `roles_managed_roles` is not checked when a role is assigned.
- `MembershipService::assignRole` does not itself require `manage_members`.
- Subtask parents must belong to the same project.
- `inherit_members` walks descendants by chaining each new inherited row, not only the direct child.
- Turning `inherit_members` off removes roles this project inherited from another project. Group expansion on the same project is kept.
- A user with `status` other than `1` is treated as logged out for ACL, including admins.
- Archived and closed project statuses are not special-cased.
- Journals are not written.
- Custom field workflow failures use `CustomFieldValidationException`. Core field workflow failures still use `WorkflowDeniedException`.
