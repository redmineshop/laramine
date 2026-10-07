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

Field rules (`type = WorkflowPermission`, `rule = readonly|required`) are enforced for the disablable core fields on create and update. On create they are read for the initial status id. Across every applicable role, a missing row leaves the field unconstrained. If every role has a row and one of them is `required`, the field is required. Custom field ids are enforced by `CustomValueService` when values are written; see [custom-fields.md](custom-fields.md).

The MVP smoke for projects, membership, workflow, and issues is [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md). A green smoke is Laramine behavior. Parity is **NOT VERIFIED**. This slice is not a 0.1 tag.

## Journals

`IssueService::update` writes one `journals` row when a tracked attribute changes or the caller sends a non-blank `notes` string. `journalized_type` is `Issue`. `user_id` is the actor. Blank notes are stored as null and do not create a journal by themselves. Create does not write a journal.

A non-blank note requires `add_issue_notes`. `private_notes` true requires `set_notes_private` and is stored on that journal, including when the journal also has property details. Attribute changes still require `edit_issues` or `edit_own_issues`. A notes-only update does not require edit permission. Active admins bypass these checks.

Tracked details use `journal_details.property = attr` and `prop_key` set to the issue column, in this order: `status_id`, `done_ratio`, `subject`, `description`, `priority_id`, `assigned_to_id`, `start_date`, `due_date`, `estimated_hours`, `is_private`, `parent_id`. Compared values are strings. `status_id` and `priority_id` are decimal id strings. `done_ratio` is an integer string. Custom-field diffs are not written.

`IssueRelationService::add` inserts `issue_relations` (`relates`, `blocks`, `duplicates`, `precedes`, or `copied_to`) and one journal on the source issue. That detail uses `property = relation`, `prop_key` the relation type, and `value` the other issue id. It requires `manage_issue_relations`.

`IssueHistoryPresenter` is the issue-show view model. It is not an HTTP response.

- Zero visible journals, no spent time, and no associated revisions: no History block and no tab labels.
- History is listed when any journal is visible. Notes is listed when any visible journal has note text or a thumbnail. Property changes is listed when any visible journal has a `journal_details` row. Spent time is listed when the actor has `view_time_entries` and the issue's time-entry hours sum is greater than zero. Associated revisions is listed when at least one linked changeset is visible. A journal with neither a note, a thumbnail, nor a detail is History only. Spent time and Associated revisions can appear without a History tab when the issue has no visible journals.
- History lists every visible journal. The Notes tab keeps journals with note text or a thumbnail, including a journal that also has details, and it keeps those property lines. A detail-only journal without a thumbnail is omitted. The Property changes tab keeps journals that have details, including a journal that also has a note. On that tab the note text is omitted and the only header control is reaction. Attachment rows stay on that copy.
- Anchors are `#1`, `#2`, … in visible order. They are not `journals.id`. The href is `#note-n` for that same index. Copy link is `{protocol}://{host_name}/issues/{id}#note-n` for that same visible index. `protocol` is `http` or `https` (blank means `http`). `host_name` is a host and optional port. A blank or unusable host uses `localhost:3000`. There is no issue HTTP route.
- An attribute with both values reads `{label} changed from {old} to {new}`. The HTML line wraps those two values in `em`. Status uses the status name. Done ratio uses the integer string. A missing old value reads `set to`. A missing new value reads `deleted`.
- A `relates` detail reads `Related to {tracker} #{id}: {subject} added`. That line is not italicized.
- Note text is escaped. A textile `*emphasis*` span becomes `em`. Other textile marks stay plain text.
- On History and Notes, a journal with note text exposes reaction (`thumbs-up`). Quote is added when the actor has `add_issue_notes`. Edit (pencil) is added when the actor has `edit_issue_notes`, or `edit_own_issue_notes` and `journals.user_id` is that actor. More (`⋯`) is always on those two tabs. A journal without note text exposes reaction and more only.
- The more menu lists Download all files when that journal has more than one attachment, then Copy link, then Delete when edit is allowed for that note. The issue show model offers the same Download all files item when the issue container itself has more than one attachment. `AttachmentArchive` returns a zip named `issue-{id}.zip` or `journal-{id}.zip`. Repeated filenames keep the extension and insert `(2)`, `(3)`, and so on. One attachment is not an archive. A private journal stays hidden, and the zip is refused, without `view_private_notes`. The show model records the control. There is no HTTP route.
- Image filenames (`bmp`, `gif`, `jpg`, `jpe`, `jpeg`, `png`, `webp`) are thumbnails when `thumbnails_enabled` is on. The default is off. `thumbnails_size` defaults to 100 and is stored on the show model. Thumbnail image bytes are not rendered. A non-image file does not put a detail-only journal on Notes.
- Spent time rows are ordered by `spent_on` descending, then `created_on`, then `id`. Hours are rounded to two decimals. `time_entries_visibility` applies to those rows: `all`, or `own` where `user_id` is the actor. Only roles that grant `view_time_entries` count, and several of those roles use the most open value. The tab stays when the hours sum is above zero even if that filter leaves no rows. Active admins see every row. There is still no time-entry write service.
- Associated revisions are `changesets` rows joined through `changesets_issues`, newest `committed_on` first. The actor needs `view_changesets` on the repository's project. The repository `type` string is stored and is not used to fetch commits.
- After `IssueService::update` returns, the caller passes `justUpdated: true`. The show model then carries the flash `✓ Successful update.` with tone `green`.

`JournalNoteService` persists quote, edit, and delete. The permission checks are `JournalNoteAccess`, the same rules the show model uses for the markers. Active admins pass through `PermissionService`.

- Quote requires `add_issue_notes` and a journal the actor can see. It inserts a new `journals` row on the same issue. `user_id` is the actor. `notes` is `{name} wrote:` followed by the source note, one `> ` line per source line. The name is the author's first and last name, the login when that name is blank, or `User`. A `<pre>` block in the source is stored as `[...]`. `updated_on` stays null. A private source is stored as a private note, and that copy does not require `set_notes_private`.
- Edit requires `edit_issue_notes`, or `edit_own_issue_notes` when `journals.user_id` is the actor, and a visible journal that already has note text. Blank text is rejected. The same text after trimming does not write. A real change stores the trimmed note, sets `updated_by_id` to the actor and `updated_on` to now, and leaves `user_id`, `created_on`, `private_notes`, and `journal_details` as they were.
- Delete uses the same permission rule as edit. A journal with no `journal_details` is removed, including `reactions` rows whose `reactable_type` is `Journal` and whose `reactable_id` is that journal. A journal that has details keeps the row and the details, sets `notes` to null, and stamps `updated_by_id` and `updated_on`. `private_notes` stays as it was.
- A detail-only journal, a non-issue journal, or a private journal the actor cannot view is rejected. Quote, edit, and delete that change history also touch the issue `updated_on`.
- The notes fieldset is present when the actor can add a note or edit the issue. The Private notes checkbox is present only with `set_notes_private`, and the form leaves it unchecked.

A private journal is omitted for an actor without `view_private_notes`, including the author. An active admin sees it. Query filters use the same rule; see [queries.md](queries.md). The security/parity gate for this slice is [journals-parity-gate.md](journals-parity-gate.md). Parity is **NOT VERIFIED**.

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
- `roles.time_entries_visibility` is applied on the issue history Spent time tab. Issue query `spent_hours` totals do not use it. There is no time-entry write service.
- `roles.users_visibility` is stored and is not applied. User administration is outside this slice. Users and authentication are a spec hole in [users-auth-spec.md](users-auth-spec.md): **NOT VERIFIED**, not a 0.1 tag, and not an invitation to add login.
- `roles_managed_roles` is stored and is not checked when a role is assigned.
- `MembershipService::assignRole` does not itself require `manage_members`.
- Subtask parents must belong to the same project.
- `inherit_members` walks descendants by chaining each new inherited row, not only the direct child.
- Turning `inherit_members` off removes roles this project inherited from another project. Group expansion on the same project is kept.
- A user with `status` other than `1` is treated as logged out for ACL, including admins.
- Archived and closed project statuses are not special-cased.
- Relation-add journals are written on the source issue only. The other issue does not get a row.
- A private journal stays hidden from its author when that user lacks `view_private_notes`.
- Quote writes a new journal immediately. It does not fill a notes field for a later update.
- Quoting a private journal stores the new note as private. The actor does not need `set_notes_private` for that copy.
- Edit rejects a blank note. Clearing a note is the delete action.
- Delete removes a notes-only journal. A journal that also has details keeps the row and the details, with `notes` set to null.
- Copy link builds `/issues/{id}#note-n` from `protocol` and `host_name`. There is no issue HTTP route. A blank host uses `localhost:3000`.
- Thumbnail files are marked on the show model. The image bytes are not rendered.
- Download all files returns zip bytes from the domain service. It does not register an HTTP route.
- Changeset rows can be listed on the history tab. Commit sync, diffs, and repository browse are not implemented.
- Custom field workflow failures use `CustomFieldValidationException`. Core field workflow failures still use `WorkflowDeniedException`.

## Queries

Saved issue queries and the shipped filter operators live in `app/Domain/Queries`. Storage, visibility, and the operator table are described in [queries.md](queries.md). Project, time entry, and user queries are stubs. The HTTP API is not part of this slice.
