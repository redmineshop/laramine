# Domain slice (projects, membership, issues)

Laramine services in `app/Domain` maintain the P0 tables. This is not a Redmine parity claim. The HTTP API is not part of this slice.

## Projects

`ProjectService` creates and moves the project nested set (`parent_id`, `lft`, `rgt`). A move appends the subtree as the last child of the new parent, or as a new root when the parent is null. Moving a project under itself or under a descendant is rejected and rolled back. When the parent changes, roles this project inherited from another project are removed and the chain on descendants is removed with them. If `inherit_members` is still on and the new parent exists, that parent's roles are copied again. The copy walks descendants that also inherit. A user's copy on a child inherits from the group's row on that child, because descendants are copied before the group's users on the same project.

`enableModule` / `disableModule` use `enabled_modules.name`. Names are the ten module keys in `PermissionCatalog::ENABLED_MODULES`. The `project` permission bucket is not a module row.

`attachTracker` writes `projects_trackers`. A tracker can be used on a project only when that row exists.

## Permissions

`roles.permissions` stays a text column. Writes through the `Role` model store a JSON array of permission name strings:

```json
["view_issues", "add_issues"]
```

Reads also accept a Redmine YAML symbol list (`- :view_issues`) so an ETL load can be checked before it is rewritten as JSON. Empty or unrecognized text grants nothing beyond public permissions.

`PermissionCatalog` holds the 80 Redmine 7.0.1 names, their module, and the flags `public`, `read`, `require=loggedin`, and `require=member`. `view_issues` is a read permission. `PermissionService::allowed($user, 'view_issues', $project, $tracker)` is the check. The tracker argument is optional. The same strings are registered as Gates. `ProjectPolicy` and `IssuePolicy` cover view, update, and delete.

Evaluation order:

1. Unknown names throw.
2. Project status is checked before the admin bypass. `status` 9 (archived) denies every action, including for an active admin. `status` 5 (closed) allows only permissions whose catalog flag is read, including for an active admin. Any other status except `1` (active) denies the action. An active project continues.
3. An active admin (`users.admin` and `users.status = 1`) is allowed. That bypass still skips a disabled module when the status gate allowed the action.
4. Modular permissions require that module on the project.
5. `require=loggedin` and `require=member` are enforced even if the role lists the name. Anonymous cannot use logged-in or member permissions. A non-member cannot use member permissions.
6. Public names (`view_project`, `search_project`, `view_members`) are implied when the project is visible.
7. Otherwise the name must appear on an applicable role. When a `Tracker` is passed, the five masked names also have to pass that role's tracker mask.

Applicable roles are memberships of the user and of the user's groups. With no membership, a visible public project uses the Non member role (`builtin = 1`) for a logged-in user and the Anonymous role (`builtin = 2`) for a guest. Builtin roles are not assigned through `members`.

`roles.settings` stores a per-tracker mask for `view_issues`, `add_issues`, `edit_issues`, `add_issue_notes`, and `delete_issues`. `permissions_all_trackers` set to `"0"` keeps only the integer ids in `permissions_tracker_ids` for that name. A missing setting, or any flag other than `"0"`, means every tracker. An empty id list matches no tracker. `edit_own_issues` is not masked. A call that omits the tracker does not apply the mask, so a project-level allow stays true. Roles that grant the name are OR-ed. `IssueVisibility` applies the `view_issues` mask when it builds the issue list.

`ManagedRoleGuard::assign` is the membership write that checks the actor. It requires `manage_members` on the project, then `all_roles_managed` or a `roles_managed_roles` row for the target role. Roles that grant `manage_members` are unioned. `all_roles_managed` ignores the join table. An active admin who is allowed `manage_members` may assign any `builtin = 0` role. Builtin roles cannot be assigned. `MembershipService::assignRole` stays the unchecked write used by inheritance and group expansion.

Project visibility follows the same statuses. An archived project is visible only to an active admin. A closed project is visible the same way as an active project. An unknown status is hidden. Members of a visible project see it. A public project is visible to a guest and to a logged-in user with no membership.

`time_entries_visibility` filters spent-time rows. An archived project, and any status other than active or closed, yields no rows, including for an active admin. On an active or closed project, `all` shows every row and `own` keeps rows whose `user_id` is the actor. Several roles that grant `view_time_entries` use the most open value. Any other stored value contributes nothing. An active admin on an active or closed project sees every row. This list does not also require the `time_tracking` module. IssueQuery `spent_hours` uses the same mode. Writes, rollup, and TimeEntryQuery are compared on the time entries and attachments row.

`issues_visibility` filters issue lists:

| Value | Effect |
| --- | --- |
| `all` | Every issue in the project, including private ones |
| `default` | Non-private issues, plus private issues the user authored or is assigned (including via a group) |
| `own` | Only issues the user authored or is assigned |

Several roles use the most open value. This follows the usual Redmine `Issue.visible` split between `all` and `default`.

`users_visibility` filters which users and groups a viewer can see. `UserVisibility` is that check. A new issue assignee must pass it. The previously stored assignee may stay. The user directory and `UserQuery` use that same scope and are compared on the users and authentication checklist row.

| Value | Effect |
| --- | --- |
| `all` | Every active user and group. Locked accounts stay hidden. |
| `members_of_visible_projects` | Active users and groups who have a membership on a project the viewer can see, plus the viewer when that viewer is logged in. |

Several roles use the most open value (`all` over `members_of_visible_projects`). Membership roles are used when the viewer has any membership, including through a group. A logged-in user with no membership uses the Non member role. A guest uses the Anonymous role. An active admin sees every user and group, including locked accounts. `AnonymousUser` rows are omitted. Inactive users are hidden from everyone except an active admin, even when they still have a membership.

## Workflow and issues

`IssueService::create` and `update` require `add_issues`, or `edit_issues` / `edit_own_issues`. Parent changes also require `manage_subtasks`. Subtasks stay in the same project. Each issue tree uses `parent_id`, `root_id`, `lft`, and `rgt`, with the root at `lft = 1`.

Status changes read `workflows` rows with `type = WorkflowTransition` for the user's roles and the tracker. A new issue uses `old_status_id = 0`. Rows with `author = 0` and `assignee = 0` always apply. `author = 1` also applies to the issue author. `assignee = 1` also applies to the assignee or to a member of an assignee group. The assignee considered for that flag is the assignee already stored on the issue, not a new assignee sent in the same update. If no initial row matches, the tracker's default status is allowed. Admins skip the matrix. Saving without a status change does not need a self-transition row.

Field rules (`type = WorkflowPermission`, `rule = readonly|required`) are enforced for the disablable core fields on create and update. On create they are read for the initial status id. Only roles that grant `add_issues`, `edit_issues`, or `edit_own_issues` take part in transitions and field rules. A role outside that set does not add a transition and does not loosen a rule by lacking a row. Across the roles that remain, a missing row leaves the field unconstrained. If every such role has a row and one of them is `required`, the field is required. Two rows for the same role resolve to `required` when either row is `required`. Custom field ids are enforced by `CustomValueService` when values are written; see [custom-fields.md](custom-fields.md).

Closing an open issue is refused when any descendant is still open, and when a `blocks` relation has this issue as `issue_to_id` and `issue_from_id` is still open. A closed blocker does not count. `relates` does not block. Reopening is refused when the resulting parent or ancestor is closed. An open issue cannot be created or moved under a closed parent. A closed child may stay under a closed parent. These checks run after the workflow matrix and apply to every actor, including an active admin. The messages contain `open subtask`, `blocked by`, `parent issue is closed`, and `closed parent`.

The MVP smoke for projects, membership, workflow, and issues is [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md). The core checklist acceptance section there marks **PASS** only for the happy paths stored by `tests/Feature/CoreChecklistSmokeTest.php`. A green smoke is Laramine behavior. The identity, nested-set, and workflows checklist rows are **VERIFIED** only by the parity tests named in [parity-checklist.md](parity-checklist.md). This slice is not a 0.1 tag.

## Journals

`IssueService::update` writes one `journals` row when a tracked attribute changes, a custom value changes, or the caller sends a non-blank `notes` string. `journalized_type` is `Issue`. `user_id` is the actor. Blank notes are stored as null and do not create a journal by themselves. Create does not write a journal.

A non-blank note requires `add_issue_notes`. `private_notes` true requires `set_notes_private` and is stored on that journal, including when the journal also has property details. Attribute changes still require `edit_issues` or `edit_own_issues`. A notes-only update does not require edit permission. Active admins pass those permission checks when the project status allows the action. An archived project denies them. A closed project denies writes, including notes and edits.

Tracked details use `journal_details.property = attr` and `prop_key` set to the issue column, in this order: `status_id`, `done_ratio`, `subject`, `description`, `priority_id`, `assigned_to_id`, `start_date`, `due_date`, `estimated_hours`, `is_private`, `parent_id`. Compared values are strings. `status_id` and `priority_id` are decimal id strings. `done_ratio` is an integer string. Custom-field diffs follow those rows. Each changed field is one detail with `property = cf`, `prop_key` the custom field id, and the stored strings joined by a comma when the field has several values. A cleared field stores null. History lines for those rows are described below. See [custom-fields.md](custom-fields.md).

`IssueRelationService::add` inserts `issue_relations` (`relates`, `blocks`, `duplicates`, `precedes`, or `copied_to`) and one journal on the source issue. That detail uses `property = relation`, `prop_key` the relation type, and `value` the other issue id. It requires `manage_issue_relations`.

`IssueHistoryPresenter` is the issue-show view model. It is not an HTTP response.

- Zero visible journals, no spent time, and no associated revisions: no History block and no tab labels.
- History is listed when any journal is visible. Notes is listed when any visible journal has note text or a thumbnail. Property changes is listed when any visible journal has a visible detail line. Spent time is listed when the actor has `view_time_entries` and the issue's time-entry hours sum is greater than zero. Associated revisions is listed when at least one linked changeset is visible. A journal with neither a note, a thumbnail, nor a visible detail line is History only. Spent time and Associated revisions can appear without a History tab when the issue has no visible journals.
- History lists every visible journal. The Notes tab keeps journals with note text or a thumbnail, including a journal that also has details, and it keeps those property lines. A detail-only journal without a thumbnail is omitted. The Property changes tab keeps journals that have details, including a journal that also has a note. On that tab the note text is omitted and the only header control is reaction. Attachment rows stay on that copy.
- Anchors are `#1`, `#2`, … in visible order. They are not `journals.id`. The href is `#note-n` for that same index. Copy link is `{protocol}://{host_name}/issues/{id}#note-n` for that same visible index. `protocol` is `http` or `https` (blank means `http`). `host_name` is a host and optional port. A blank or unusable host uses `localhost:3000`. There is no issue HTTP route.
- Journals are ordered by `created_on`, then `id`. Anchors count only journals the actor can see.
- An attribute with both values reads `{label} changed from {old} to {new}`. The HTML line wraps those two values in `em`. Status, priority, tracker, category, target version, and project use the record name. Assignee uses the person's name. Done ratio, dates, and estimated hours use the stored text. `is_private` uses Yes and No. Parent task uses `#{id}`. Description reads `Description updated` and does not show the old or new text. A missing old value reads `set to`. A missing new value reads `deleted`.
- A custom-field detail uses the field name as the label. A field the actor cannot see, and a missing field, are omitted. Several stored values are split on comma and shown joined by `, `. List, string, link, int, float, and date keep the stored text. Bool uses Yes and No. Enumeration, user, and version use the option, person, or version name. An attachment value uses the file name. Progress bar appends `%`. Text reads `{name} updated`. A multiple field with no old value reads `{name} {values} added`, with the values in `em`.
- An attachment detail with a new file name reads `File {filename} added`. A removed file reads `File deleted ({filename})`.
- A relation detail names the other issue as `{tracker} #{id}: {subject}`. Added reads `{label} {tracker} #{id}: {subject} added` and is not italicized. Removed reads `{label} deleted ({tracker} #{id}: {subject})`. The line is omitted when that issue is missing or the actor cannot see it. Labels are Related to, Blocks, Duplicates, Precedes, and Copied to.
- Note text is escaped. A textile `*emphasis*` span becomes `em`. Other textile marks stay plain text.
- On History and Notes, a journal with note text exposes reaction (`thumbs-up`). Quote is added when the actor has `add_issue_notes`. Edit (pencil) is added when the actor has `edit_issue_notes`, or `edit_own_issue_notes` and `journals.user_id` is that actor. More (`⋯`) is always on those two tabs. A journal without note text exposes reaction and more only.
- The more menu lists Download all files when that journal has more than one attachment, then Copy link, then Delete when edit is allowed for that note. The issue show model offers the same Download all files item when the issue container itself has more than one attachment. `AttachmentArchive` returns a zip named `issue-{id}.zip` or `journal-{id}.zip`. Repeated filenames keep the extension and insert `(2)`, `(3)`, and so on. One attachment is not an archive. A private journal stays hidden, and the zip is refused, without `view_private_notes`. The show model records the control. The bytes are the container route under Attachments, which uses a different file name and duplicate count.
- Image filenames (`avif`, `bmp`, `gif`, `jpg`, `jpe`, `jpeg`, `png`, `webp`) are thumbnails when `thumbnails_enabled` is on. The default is off. `thumbnails_size` defaults to 100 and is stored on the show model. Thumbnail image bytes are not rendered. A non-image file does not put a detail-only journal on Notes.
- Spent time rows are ordered by `spent_on` descending, then `created_on`, then `id`. Hours are rounded to two decimals. `time_entries_visibility` applies to those rows: `all`, or `own` where `user_id` is the actor. Only roles that grant `view_time_entries` count, and several of those roles use the most open value. The tab stays when the hours sum is above zero even if that filter leaves no rows. Active admins see every row on an active or closed project. An archived project leaves the list empty. Writes go through `TimeEntryService`.
- Associated revisions are `changesets` rows joined through `changesets_issues`, newest `committed_on` first. The actor needs `view_changesets` on the repository's project. The repository `type` string is stored and is not used to fetch commits.
- After `IssueService::update` returns, the caller passes `justUpdated: true`. The show model then carries the flash `✓ Successful update.` with tone `green`.

`JournalNoteService` persists quote, edit, and delete. The permission checks are `JournalNoteAccess`, the same rules the show model uses for the markers. Active admins pass through `PermissionService`.

- Quote requires `add_issue_notes` and a journal the actor can see. It inserts a new `journals` row on the same issue. `user_id` is the actor. `notes` is `{name} wrote:` followed by the source note, one `> ` line per source line. The name is the author's first and last name, the login when that name is blank, or `User`. A `<pre>` block in the source is stored as `[...]`. `updated_on` stays null. A private source is stored as a private note, and that copy does not require `set_notes_private`.
- Edit requires `edit_issue_notes`, or `edit_own_issue_notes` when `journals.user_id` is the actor, and a visible journal that already has note text. Blank text is rejected. The same text after trimming does not write. A real change stores the trimmed note, sets `updated_by_id` to the actor and `updated_on` to now, and leaves `user_id`, `created_on`, `private_notes`, and `journal_details` as they were.
- Delete uses the same permission rule as edit. A journal with no `journal_details` is removed, including `reactions` rows whose `reactable_type` is `Journal` and whose `reactable_id` is that journal. A journal that has details keeps the row and the details, sets `notes` to null, and stamps `updated_by_id` and `updated_on`. `private_notes` stays as it was.
- A detail-only journal, a non-issue journal, or a private journal the actor cannot view is rejected. Quote, edit, and delete that change history also touch the issue `updated_on`.
- The notes fieldset is present when the actor can add a note or edit the issue. The Private notes checkbox is present only with `set_notes_private`, and the form leaves it unchecked.

A private journal is omitted for an actor without `view_private_notes`, including the author. An active admin sees it. Query filters use the same rule; see [queries.md](queries.md). The journals checklist row is **VERIFIED** only by `tests/Parity/JournalParityTest.php`. HTTP download, PNG thumbnails, and attachment or relation-removal journal writes are compared on the time entries row. SCM history stays open in [journals-parity-gate.md](journals-parity-gate.md). This is not a 0.1 tag.

## Time entries

`TimeEntryService` creates, updates, and deletes `time_entries`. It is not an HTTP time log and it does not sign anyone in. Web sign-in is in [users-auth-spec.md](users-auth-spec.md). The time entries and attachments checklist row is **VERIFIED** by `tests/Parity/TimeEntryParityTest.php` and `tests/Parity/AttachmentParityTest.php`. That comparison is not a 0.1 tag.

Create requires `log_time` on the project. `time_tracking` must be enabled unless the actor is an active admin. A closed or archived project denies the write, including for an active admin. `author_id` is the actor and is not changed later. `user_id` defaults to the actor. Setting it to anyone else requires `log_time_for_other_users` and an active user (`type` User, `status` 1). A group or an inactive user is rejected. Passing the user already stored on an update does not ask for that permission again. Passing null, or the actor's id, stores the actor. The spent user's project membership is not checked.

`issue_id` may be omitted. When it is set, that issue's project must be the time entry's project. `project_id` is chosen at create and is not changed. `activity_id` must be an active `TimeEntryActivity`. A system activity (`project_id` null) is available unless this project has a child row with that `parent_id`. That child replaces the parent: only an active child is accepted, and an inactive child hides the parent as well. An activity that belongs to another project is rejected. When `activity_id` is omitted on create, `TimeEntryActivityDefaults` stores one: the only available activity, otherwise the first default among the roles on the user's membership for that project (a role inherited from a group counts; lowest `builtin`, then lowest `position`, then lowest `id`) that matches an available activity or the project activity that replaces it, otherwise the project copy of the marked default, otherwise the marked default. The marked default is the `TimeEntryActivity` with `is_default`, lowest `position`, then lowest `id`. A user with no membership row skips the role default. Update still requires an activity.

`hours` accepts a positive decimal (`1.5` or `1,5`), a clock pair (`1:30`, minutes divided by 60), or an hours-and-minutes phrase (`2h15m`, `2h`, `45m`). Zero and other text are rejected. Display follows `timespan_format`: `minutes` renders `h:mm` with a 60-minute carry, and `decimal` renders two fractional digits with a thousands separator. `spent_on` is a calendar date `Y-m-d`. `tyear` is the ISO week-year, `tmonth` is the calendar month, and `tweek` is the ISO week number. Every save writes those three columns from `spent_on`, including a comment-only edit. A report groups by the stored columns, so a row that has not been saved again keeps the week number already in the table. `comments` are optional, trimmed, and stored as null when blank. Text longer than 1024 characters is rejected. `timelog_required_fields` may require `issue_id` and `comments`. The value may be a JSON array, a comma list, or `- name` lines. Other tokens are ignored. Logging time does not change `issues.updated_on`.

Create and update write `custom_field_values` through `CustomValueService` inside the same transaction. A required visible time-entry field with no value rolls the new row back. Update and delete require `edit_time_entries`, or `edit_own_time_entries` when `user_id` is the actor. Delete removes the row and `custom_values` whose `customized_type` is `TimeEntry` for that id.

`IssueSpentHours::spent` sums `time_entries.hours` for that issue. `total` adds the same sum for every descendant in the issue nested set. Those sums are not filtered by `time_entries_visibility`. IssueQuery `spent_hours`, the projected column, and the `spent_time` filter keep the visibility rule in [queries.md](queries.md). `TimeEntryQueryRunner` lists rows, builds a criteria report (at most three of project, user, activity, issue, tracker, status, version, and category, across year, month, week, or day), and writes a CSV whose last line is `Total`. A project-scoped query stays on that project.

## Attachments

`POST /attachments/upload` stores an unbound file and returns `{id}.{digest}`. `POST /attachments/claim` binds that token to an issue or an issue journal when the actor can edit that container, then writes an `attachment` journal detail (`prop_key` is the attachment id, `value` is the filename). Delete writes the filename into `old_value` and removes the file, the thumbnail cache, and the row. Custom-field files stay on the custom-field routes.

`GET /attachments/{id}` sends the bytes. `Content-Type` is the stored type, or `application/octet-stream`. PDF, image, text, audio, and video are `inline`. Other types are `attachment`. Downloads increment only for `Project` and `Version` containers. `GET /attachments/{id}/thumbnail` renders a PNG when `thumbnails_enabled` is on and the filename is an image (`avif`, `bmp`, `gif`, `jpg`, `jpe`, `jpeg`, `png`, `webp`). The edge is the requested size from 1 to 800, otherwise `thumbnails_size`, otherwise 100. The image is scaled down so its longest edge fits, and it is never enlarged. The cache file is `thumbnails/{id}_{digest}_{edge}.png` and is reused while it exists. PNG is decoded in process. GIF, JPEG, BMP, WebP, and AVIF go through `intervention/image` on the GD driver (`composer` package `intervention/image`, PHP extension `gd`). A format GD cannot read, or a file it rejects, has no thumbnail. PDF is not thumbnailed. `GET /attachments/{issues|journals|projects|versions}/{id}/download` zips the readable files on that container. The name is `{type}-{id}-attachments.zip`. Journal entries are the attachment ids named by that journal's details, including a private note when the issue is visible. A project zip needs `view_files`. A version zip also needs `view_issues` on that project. The first repeated name is `file(1).ext`. The zip is refused when the readable `filesize` sum is greater than `bulk_download_max_size` kilobytes (default 102400). News, documents, messages, and wiki pages are not on this route. `attachment_max_size` is kilobytes and defaults to 5120. An empty file is rejected. A non-empty allow-list is a whitelist, and the deny-list still rejects a match.

Removing a relation journals both issues. The other issue stores the reverse type (`blocks` / `blocked`, `duplicates` / `duplicated`, `precedes` / `follows`, `copied_to` / `copied_from`, `relates` / `relates`). Adding a relation still journals the source issue only.

## Notifications

`IssueNotifier` and `AccountNotifier` queue `RedmineNotificationMail` after the domain transaction returns. One message is queued for each `email_addresses` row with `notify` true, in id order. `notify` false, `0`, or `"0"` on an issue create or update suppresses that mail. A missing `notify` sends.

`users.mail_notification` `none`, blank, or any value outside the six legal ones never receives mail. `all`, `selected`, and `only_my_events` receive when the user is the author or the current or previous assignee. `only_assigned` receives for the current or previous assignee. `only_owner` receives for the author. A group assignee expands through `groups_users`. Project-level recipients are user members with `all`, and `selected` members whose `members.mail_notification` checkbox is on. A group membership with that checkbox on, or a group whose own `mail_notification` is `all`, adds the group's active users without applying `only_*` again. Watchers of the issue are included unless their preference is `none` or blank. `user_preferences.others` flag `no_self_notified` drops the actor.

A private journal that has notes and no details is sent only to users who may `view_private_notes`. An active admin is included. The author without that permission is not. A private note that also has details is still sent to the other recipients, and the note text is omitted from their body.

`settings.notified_events` is a JSON array. A missing or invalid value uses the default list. A stored `[]` sends nothing. Unknown names are dropped. The default list enables `issue_added`, `issue_updated`, `issue_note_added`, `issue_status_updated`, `issue_assigned_to_updated`, and `issue_priority_updated`, plus the unbuilt news, document, file, message, and wiki names. `issue_fixed_version_updated` and `issue_attachment_added` are known and off until stored. An empty journal does not emit. `issue_updated` matches any journal with a note or a detail. The specific events match only `status_id`, `assigned_to_id`, `priority_id`, `fixed_version_id`, and an `attachment` detail. Other attribute, `cf`, and `relation` details match only the catch-all. Unbuilt event names never match a journal and are never sent. Quote and relation-add write a journal and send issue-edit mail. Note edit and delete do not. Claiming or deleting a container attachment does not write a second journal: mail uses the row `AttachmentContainerService` already stores through `IssueJournalWriter`. `issue_attachment_added` matches an attachment detail whose new value is the filename. A removal detail matches only `issue_updated`.

Account mail: self-registration mode `1` emails the user a `register` token at `/account/activate?token=`. Mode `2` emails active administrators "New user account" and does not include a token. Mode `3` sends nothing. Lost password emails the `recovery` token at `/account/lost_password?token=`. A registered user in email-activation mode receives the activation mail instead. Administrator create sends "Account information" including the plaintext password. Administrator activate sends "Account activated". Lock and unlock send "Account locked" and "Account unlocked" to that account's notify addresses.

The message id is `redmine.{kind}-{id}.{YmdHis}@{host}` without angle brackets and without a random suffix. The host is the domain of `mail_from` when that value contains `@`, otherwise `host_name`, otherwise `localhost`. Issue add uses the issue id and `issues.created_on` for both `Message-ID` and `References`. Issue edit uses the journal id and `journals.created_on` for `Message-ID`, and the issue id with `issues.created_on` for `References`. Account mail uses kind `account-{userId}.{action}` and the token id when a token exists, otherwise the user id. `References` is `redmine.user-{id}.{user created_on stamp}`. Common headers are `X-Mailer`, `X-Redmine-Host`, `X-Redmine-Site`, `X-Redmine-Sender`, `X-Auto-Response-Suppress`, and `Auto-Submitted`. Issue mail also sets `X-Redmine-Project`, `X-Redmine-Issue-Id`, `X-Redmine-Issue-Author`, and `X-Redmine-Issue-Assignee`. The From address is the email in `mail_from`, or `noreply@{host}`. The From name is the application title.

The issue subject is `[{project} - {tracker} #{id}] ({status}) {subject}` on add. An edit includes `({status}) ` only when the journal has an `attr` `status_id` detail. The status name is the current issue status.

The outbound mail checklist row is **VERIFIED** only by `tests/Parity/NotificationParityTest.php`. News, documents, files, messages, and wiki notifications stay **NOT VERIFIED**. This is not a 0.1 tag.

## Activity

`ActivityProvider` lists issues (`created_on`), journals (`created_on`), and time entries (`created_on`, not `spent_on`). An empty journal is skipped. A private note with no details is skipped without `view_private_notes`. A private journal that also has details stays in the list. `issue_tracking` and `time_tracking` must be enabled, including for an administrator. Projects are status active (`1`) or closed (`5`). Archived projects are excluded. Issue rows use `IssueVisibility`. Time rows use `TimeEntryVisibility`. `view_project` is required.

The window ends at the start of `from` (`Y-m-d`), or today when `from` is omitted, and starts `days` earlier. `days` comes from the query, otherwise `activity_days_default`. A missing setting is 30. `0` is a valid span. Events satisfy `created_on >= end - days` at `00:00:00` and `created_on < end + 1 day`. Sort is `at` descending, then kind `issue`, `journal`, `time_entry`, then id descending.

An issue or journal title is `{Tracker} #{id} ({Status}): {subject}` using the current tracker and status. A time title uses `HourValue` decimal formatting (`1.50`) plus the activity name, and ` on #{issue_id}` when the entry has an issue. The row is a `TimeEntry`. An attachment claim or delete shows up as the issue journal that service already wrote, not as a separate activity kind. The author is the issue author, the journal user, or the time entry user.

`AtomFeed` writes `tag:{host},{date}:{kind}/{id}`, a title, `updated` as `Y-m-dTH:i:sZ`, the author name, and a category. The feed author is the authenticated user's login. `GET /activity` and `GET /activity.atom` are global. `GET /projects/{identifier}/activity` and `activity.atom` are project-scoped. `GET /issues.atom` and `GET /projects/{identifier}/issues.atom` list visible issues, newest id first. `GET /my.atom` is the same activity feed for the feed user. A `feeds` token in `key`, or a session user, opens these routes. An API key does not. Anonymous is not served. The HTML page is a short Blade list and is not a Redmine screen.

The activity checklist row is **VERIFIED** only by `tests/Parity/ActivityParityTest.php` for issues, journals, and time entries. News, documents, wiki, messages, files, and changesets stay **NOT VERIFIED**. This is not a 0.1 tag.

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
- A permission check that omits the tracker does not apply `roles.settings` masks. `edit_own_issues` is not tracker-scoped.
- `roles.time_entries_visibility` is applied on the issue history Spent time tab, on spent-time row lists, and on IssueQuery `spent_hours` totals, the projected column, and the `spent_time` filter. `IssueSpentHours` rollup is not filtered by that visibility. The spent user's membership is not checked. A new time entry without `activity_id` uses the membership role default, then the system default, as described above.
- `roles.users_visibility` is applied by `UserVisibility`. A new assignee must be visible to the actor. The assignee does not have to be a member of the issue's project. Account administration and the user directory are compared on the users and authentication row. Web sign-in is in [users-auth-spec.md](users-auth-spec.md). This is not a 0.1 tag.
- `MembershipService::assignRole` does not itself require `manage_members` or `roles_managed_roles`. `ManagedRoleGuard` does.
- An active admin still bypasses a disabled module after the project status gate. The status gate itself applies to that admin.
- Subtask parents must belong to the same project.
- `inherit_members` walks descendants by chaining each new inherited row, not only the direct child.
- Turning `inherit_members` off removes roles this project inherited from another project. Group expansion on the same project is kept.
- A user with `status` other than `1` is treated as logged out for ACL, including admins.
- Relation-add journals are written on the source issue only. The other issue does not get a row. Relation delete journals both issues, and the other issue stores the reverse type.
- A private journal stays hidden from its author when that user lacks `view_private_notes`.
- Quote writes a new journal immediately. It does not fill a notes field for a later update.
- Quoting a private journal stores the new note as private. The actor does not need `set_notes_private` for that copy.
- Edit rejects a blank note. Clearing a note is the delete action.
- Delete removes a notes-only journal. A journal that also has details keeps the row and the details, with `notes` set to null.
- Copy link builds `/issues/{id}#note-n` from `protocol` and `host_name`. There is no issue HTTP route. A blank host uses `localhost:3000`.
- Thumbnail files are marked on the show model from the filename. PNG thumbnails are decoded in process. Other image types need `intervention/image` and the `gd` extension, and a missing decoder leaves no thumbnail. PDF is not thumbnailed.
- The history menu zip is still `issue-{id}.zip` or `journal-{id}.zip`, still requires more than one file, and still numbers repeats from `(2)`. The container route is separate.
- Changeset rows can be listed on the history tab. Commit sync, diffs, and repository browse are not implemented.
- Custom field workflow failures use `CustomFieldValidationException`. Core field workflow failures still use `WorkflowDeniedException`.
- `settings.notified_events` is a JSON array, not a YAML list.
- Message ids have no random suffix.
- A blank `mail_notification` does not receive mail, including a watcher or a user added through a group checkbox.
- Lock and unlock queue an informational message. The account HTTP notice still does not include a token; the mail body does.
- Mail is queued after the domain transaction returns.
- Activity covers issues, journals, and time entries. News, documents, wiki, messages, files, and changesets have no provider.

## Queries

Saved issue queries and the shipped filter operators live in `app/Domain/Queries`. Storage, visibility, and the operator table are described in [queries.md](queries.md). `TimeEntryQuery` runs through `TimeEntryQueryRunner` and is compared on the time entries row. Project and user queries are stubs. The HTTP API is not part of this slice.
