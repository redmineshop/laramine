# ACL and workflow MVP gate

This is the Laramine checklist for projects, membership, issue workflow, and issue visibility against the P0c MVP: permission registry, builtin roles, module gating, group and `inherit_members` cascade, `issues_visibility`, transitions, field rules, and admin bypass.

**Parity is NOT VERIFIED.** A green smoke in this repo is Laramine behavior on MySQL 8. It is not a comparison against a Redmine 7.0.1 database. Do not describe the project as production-ready from this file. Do not treat this file as a 0.1 tag.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Permission names, module, flags | `App\Domain\Acl\PermissionCatalog` |
| Allow / deny | `App\Domain\Acl\PermissionService` |
| Members, groups, `inherited_from` | `App\Domain\Acl\MembershipService` |
| Project tree, modules, `inherit_members` | `App\Domain\Projects\ProjectService` |
| Issue list scope | `App\Domain\Acl\IssueVisibility` |
| Transitions and field-rule merge | `App\Domain\Workflow\WorkflowService` |
| Issue create / update | `App\Domain\Issues\IssueService` |

Domain behavior is described in [domain.md](domain.md). The parity rows stay **NOT VERIFIED** in [parity-checklist.md](parity-checklist.md).

## Core checklist acceptance

There is no separate `docs/core-checklist-acceptance.md`. This section is that checklist for Issues, Projects, Membership, and Workflow.

**PASS** means the named MySQL 8 Feature smoke stored the rows in that row. **PASS** is Laramine behavior. It is not **VERIFIED**, it is not a Redmine 7.0.1 comparison, and it is not a 0.1 tag. `tests/Feature/CoreChecklistSmokeTest.php` does not live under `tests/Parity`.

| Area | What the smoke stored | Status | Evidence |
| --- | --- | --- | --- |
| Projects | A child created under a parent has `lft`/`rgt` inside the parent. `issue_tracking` is enabled once. The tracker is attached on `projects_trackers`. | PASS | `test_projects_child_module_and_tracker` |
| Membership | `assignRole` stores `members` and one `member_roles` row with `inherited_from` null. That member is allowed `add_issues` and can create an issue. A user with no membership is denied `add_issues` and stores nothing. | PASS | `test_membership_assigns_role_and_allows_add_issues` |
| Workflow | A `WorkflowTransition` with `old_status_id = 0` allows create at In Progress when the tracker default is New. A second row from In Progress to Resolved, with `author` and `assignee` false, makes `allowsTransition` true and the update stores Resolved. | PASS | `test_workflow_allows_non_default_initial_status_and_next_status` |
| Issues | Create stores the project, tracker, author, tracker default status, `lft = 1`, `rgt = 2`, `root_id` equal to the issue id, and no journal. Update along New → In Progress stores that status and one `attr` / `status_id` journal detail. | PASS | `test_issue_create_stores_nested_set_and_update_writes_status_journal` |

This smoke does not move a project, does not walk `inherit_members`, and does not merge field rules. Rows in **Already covered on main** and **Smoke added for the holes** stay as written there. They are not given this **PASS** mark. The **Open, not passed** list stays open. Parity rows stay **NOT VERIFIED**.

## Already covered on main

These checks already pass in existing tests. This change does not rewrite them.

| Check | Automated by |
| --- | --- |
| Registry has 80 names, 11 modules, and the public / read / require flags sampled for `view_project`, `edit_project`, `add_project`, and `close_project` | `tests/Unit/PermissionCatalogTest.php` `test_catalog_has_eighty_names_across_eleven_modules` |
| `roles.permissions` JSON write and YAML symbol read | `test_permission_list_json_round_trip_and_yaml_symbols` and `tests/Feature/MembershipAclTest.php` `test_yaml_permission_text_is_readable_and_json_is_stored` |
| Non member and Anonymous when there is no membership; `require=member` and `require=loggedin` still deny | `test_builtin_roles_and_requirement_flags` |
| `enabled_modules` denies `issue_tracking` and `time_tracking` permissions; `view_project` stays allowed | `test_disabled_module_denies_modular_permission` |
| Group membership and `inherited_from` cascade, including a grandchild and removal from the group | `test_group_membership_and_inherit_members_cascade` |
| `issues_visibility` `all` / `default` / `own`, including private issues | `test_issues_visibility_all_default_and_own` and `tests/Feature/IssueQueryTest.php` `test_private_issues_and_own_visibility_hide_other_rows` |
| Active admin bypasses project ACL; a locked admin does not | `test_private_project_hides_non_members_and_admin_bypasses` |
| New-issue `old_status_id = 0`, author and user-assignee rows, and a missing transition rejected for a user who has `edit_issues` | `tests/Feature/IssueWorkflowTest.php` `test_create_transition_and_author_assignee_flags` |
| Core `due_date` required on create and `start_date` readonly on update | `test_subtasks_move_across_trees_and_field_rules` |
| Custom-field readonly on update, and a hidden field treating a role without access as readonly | `tests/Feature/CustomFieldValueTest.php` |
| `save_queries` denied when the role drops that name | `tests/Feature/IssueQueryTest.php` `test_negative_permission_returns_no_issues` |
| Project nested set create and move | `tests/Feature/ProjectTreeTest.php` |

## Smoke added for the holes

`tests/Feature/AclWorkflowSmokeTest.php` and `tests/Unit/PermissionCatalogTest.php` `test_mvp_issue_time_and_query_permission_flags`. MySQL 8 (`phpunit.xml`). These tests do not live under `tests/Parity`.

| # | Check | Automated by |
| --- | --- | --- |
| 1 | A child created after the parent membership, with `inherit_members`, copies the role (`inherited_from` set) and can `add_issues` | `test_mvp_later_child_inherits_membership_and_group_sees_private_project` |
| 2 | A group member sees a private project through the inherited membership; a stranger does not | same |
| 3 | Turning `inherit_members` off drops roles inherited from another project and keeps a group role granted on that project | `test_mvp_inherit_off_keeps_same_project_group_role_and_blocks_direct_revoke` |
| 4 | Revoking an inherited member role directly is rejected; revoking the source removes it | same |
| 5 | Core field rules: `required` wins over `readonly`, including clearing the value on update | `test_mvp_core_field_rules_merge_across_roles` |
| 6 | A role with no row leaves the field unconstrained; removing that role makes `readonly` apply | same |
| 7 | An active admin skips a readonly field and a missing transition; a member with `edit_issues` does not | `test_mvp_admin_bypasses_workflow_and_inactive_member_does_not` |
| 8 | An active admin keeps a modular permission when the module is disabled; a locked admin does not | same |
| 9 | A member with `status` other than `1` loses membership permissions and still receives public `view_project` on a public project | same |
| 10 | A member of the assignee group can take an assignee-only transition; another member with `edit_issues` cannot | `test_mvp_assignee_group_transition_and_visibility` |
| 11 | `issues_visibility = default` shows a private issue assigned to the user's group and hides it from another member | same |
| 12 | Two roles use the most open `issues_visibility` | same |
| 13 | Non member and Anonymous `issues_visibility` filter a public project, including Anonymous `all` | `test_mvp_builtin_roles_filter_issue_visibility` |
| 14 | `edit_own_issues` updates the author's issue and denies another author | `test_mvp_edit_own_issues_and_private_flag` |
| 15 | `edit_own_issues` still cannot take an assignee-only transition | same |
| 16 | `set_own_issues_private` lets the author set the flag and denies it for someone else's issue; create without either private permission is denied | same |
| 17 | Builtin roles cannot be assigned through `members` | `test_mvp_stored_deferrals_do_not_change_checks` |
| 18 | `roles.settings` JSON is stored; a tracker mask does not change `allowed` | same |
| 19 | A `roles_managed_roles` row persists and does not block assignment | same |
| 20 | An unknown permission name throws | same |
| — | MVP `issue_tracking`, `time_tracking` view/log/edit*, `save_queries`, and `manage_public_queries` flags | `test_mvp_issue_time_and_query_permission_flags` |

## Open, not passed

| Item | Status |
| --- | --- |
| `time_entries_visibility` | **Open for a Redmine comparison.** The issue history Spent time tab applies `all` and `own`. IssueQuery `spent_hours` totals, the projected column, and the `spent_time` filter do too (`tests/Feature/IssueQueryDepthTest.php`, `tests/Unit/IssueQueryFieldTest.php`). `TimeEntryService` writes with `log_time`, `edit_time_entries`, `edit_own_time_entries`, and `log_time_for_other_users` (`tests/Feature/TimeEntryWriteTest.php`). Not a parity pass. |
| `users_visibility` and user authentication | **Open for this gate.** `users_visibility` is still not applied. Phase 2 account gates live on the users and authentication checklist row, not here. LDAP, two-factor, OAuth, API tokens, and account administration stay deferred. This gate is not a 0.1 tag. |
| Per-tracker permission masks | **Open.** `roles.settings` is stored. `allowed` does not read it. Criterion 18 locks that deferral. |
| Managed-role enforcement | **Open.** `roles_managed_roles` is stored. `assignRole` does not read it. Criterion 19 locks that deferral. |
| Wiki, news, documents, files, repository, boards, calendar, gantt | **Open.** Names exist in the catalog. No behavior beyond the permission registry. |
| `copy_issues`, import, watchers, categories | **Open.** Names exist in the catalog. No write service. |
| Close / reopen blockers | **Open.** Relations, open subtasks, and a closed parent do not block a transition. |
| Users auth pack, HTTP, front end, MCP | **Open for this gate.** Phase 2 routes are in [users-auth-spec.md](users-auth-spec.md). LDAP, two-factor, OAuth, API tokens, and account administration stay deferred. The HTTP API and front end are still outside this gate. Not a 0.1 tag. |
| Journal Block C criteria 16–19 | **Covered by Laramine tests.** Parity is NOT VERIFIED. Quote, edit, and delete now write through `JournalNoteService`. That write path is also Laramine-only. See [journals-parity-gate.md](journals-parity-gate.md). |
| Redmine parity VERIFIED, tag 0.1 | **Open.** Not claimed. |

An open item does not authorize a parity-verified, production-ready, or 0.1 claim.
