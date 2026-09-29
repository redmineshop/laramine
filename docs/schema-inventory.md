# Redmine 7.0.1 → Laramine — Schema inventory (P0a)

**Pinned version:** Redmine **7.0.1** (`MAJOR=7`, `MINOR=0`, `TINY=1`)  
**Release date:** 2026-08-26  
**Sources (verified same tag):**
- Official release: https://www.redmine.org/releases/redmine-7.0.1.tar.gz
- GitHub mirror tag: https://github.com/redmine/redmine/tree/7.0.1 (commit `752772866745192659850a632cd2c7a159856994`)
- Official Docker image: `docker.io/library/redmine:7.0.1` (`REDMINE_VERSION=7.0.1`)

**Local schema dump:** [`sources/redmine-7.0.1-schema.rb`](sources/redmine-7.0.1-schema.rb) (structure dump only; checked in with this inventory)  
Generated via `rake db:migrate db:schema:dump` on image `redmine:7.0.1` (SQLite adapter dump). Upstream **gitignores** `db/schema.rb`; this file is a regenerated **structure dump only** (no application Ruby copied into Laravel artifacts).

**Schema AR version stamp:** `2026_05_20_164915`  
**Total core tables in dump:** **58** (no plugin tables in this inventory)  
**Product:** **Laramine** (MIT). **Strategy:** isomorphic P0 schema — keep Redmine-like table/column names for ETL/parity.

**License note:** Redmine is GPLv2. Inventory below is table/column/FK/behavior *descriptions* for reimplementation. Do not copy Redmine Ruby into Laramine.

---

## Layer summary (table counts)

| Layer | Phase | Tables | Count |
| --- | --- | ---: | ---: |
| Identity / ACL | P0 | users, email_addresses, tokens, user_preferences, auth_sources, roles, members, member_roles, groups_users, roles_managed_roles, enabled_modules, oauth_* (3) | **14** |
| Projects | P0 | projects, projects_trackers, versions, issue_categories, enumerations | **5** |
| Issues core | P0 | trackers, issue_statuses, issues, issue_relations, watchers, journals, journal_details, workflows, reactions | **9** |
| Custom fields | P0 | custom_fields, custom_fields_trackers, custom_fields_projects, custom_fields_roles, custom_values, custom_field_enumerations | **6** |
| Time / Attachments | P0 | time_entries, attachments, comments | **3** |
| Queries | P0 | queries, queries_roles, imports, import_items | **4** |
| Wiki | P1 | wikis, wiki_pages, wiki_contents, wiki_content_versions, wiki_redirects | **5** |
| SCM / Forums / News | P2 | repositories, changesets, changes, changeset_parents, changesets_issues, boards, messages, news, documents | **9** |
| Settings / Webhooks | supporting | settings, webhooks, projects_webhooks | **3** |
| **Total** | | | **58** |

**Plugin tables:** none in core 7.0.1 dump. Parallel RedmineShop plugins are **out of scope** for this inventory.

---

## Nested-set / tree (critical for 7.0.1)

| Entity | Nested set? | Columns | Notes |
| --- | --- | --- | --- |
| **projects** | **YES** | `parent_id`, `lft`, `rgt` | No `root_id`. Modified preorder traversal for subprojects. |
| **issues** | **YES** | `parent_id`, `root_id`, `lft`, `rgt` | Subtasks; each tree rooted at `root_id`. |
| wiki_pages | parent only | `parent_id` | Hierarchy via parent_id (not full nested set). |
| boards / messages | parent only | `parent_id` | Forum thread tree. |
| enumerations | parent only | `parent_id` | STI tree for some enum types. |

**Verdict for port:** Projects and Issues **still use nested set in 7.0.1**. Laravel should preserve `lft`/`rgt` (+ issue `root_id`) for ETL parity, or rebuild trees on import with a clear rebuild job.

---

## Polymorphic columns (P0-relevant)

| Table | Type column | Id column | Typical targets |
| --- | --- | --- | --- |
| `attachments` | `container_type` | `container_id` | Issue, Project, Version, Document, WikiPage, Message, News, … |
| `custom_values` | `customized_type` | `customized_id` | Issue, Project, User, TimeEntry, Version, Group, … |
| `journals` | `journalized_type` | `journalized_id` | Primarily Issue |
| `watchers` | `watchable_type` | `watchable_id` | Issue, News, Message, WikiPage, … |
| `comments` | `commented_type` | `commented_id` | News, … |
| `reactions` | `reactable_type` | `reactable_id` | Issue, Journal, News, Message, … (7.x) |
| STI `type` | `users.type`, `custom_fields.type`, `enumerations.type`, `queries.type`, `workflows.type`, `auth_sources.type`, `repositories.type`, `imports.type` | — | STI subclasses (User/Group/AnonymousUser; IssueCustomField; IssuePriority; IssueQuery; WorkflowTransition / WorkflowPermission; …) |

---

## Behaviors **not** fully expressed in SQL (defer detail to P0b/P0c)

| Area | Short note | Later phase |
| --- | --- | --- |
| Query operators | `queries.filters` stores serialized filter hash; operators (`=`, `!`, `><`, `~`, `*o`, `o`, `c`, `!*`, …) live in IssueQuery Ruby | **P0b** `query-operators.md` |
| Custom field formats | `field_format` + `format_store` + `possible_values`; validation/casting in CF format classes | **P0b** `custom-fields-spec.md` |
| Workflow field permissions | Same `workflows` table; STI `type` distinguishes transitions vs field rules (`field_name`, `rule`) | **P0c** `acl-workflow.md` |
| Role permissions | `roles.permissions` is a **serialized list of permission name symbols**, not a join table | **P0c** |
| acts_as patterns | watchable, attachable, customizable, event, search, activity, tree/nested_set — app-layer | parity notes |
| Visibility | `roles.issues_visibility` / `users_visibility` / `time_entries_visibility`; query `visibility` enum | **P0c** |
| Mailer / tokens | `tokens.action` (session, api, feeds, recovery, …) | Auth design |

---

## 1. Identity / ACL (P0) — 14 tables

### `users`
- **PK:** `id`
- **Critical columns:** `login`, `hashed_password`, `salt`, `firstname`, `lastname`, `admin`, `status`, `type` (STI: User / Group / AnonymousUser / …), `language`, `mail_notification`, `auth_source_id`, 2FA fields (`twofa_*`), `must_change_passwd`
- **FKs:** `auth_source_id` → `auth_sources.id` (optional)
- **Notes:** Emails live in `email_addresses` (not a column on users). Groups are STI rows in `users` + `groups_users`.

### `email_addresses`
- **PK:** `id`
- **Critical:** `user_id`, `address`, `is_default`, `notify`
- **FKs:** `user_id` → `users.id`

### `tokens`
- **PK:** `id`
- **Critical:** `user_id`, `action`, `value`, timestamps
- **FKs:** `user_id` → `users.id`

### `user_preferences`
- **PK:** `id`
- **Critical:** `user_id`, `hide_mail`, `time_zone`, `others` (serialized)
- **FKs:** `user_id` → `users.id`

### `auth_sources`
- **PK:** `id`
- **Critical:** LDAP/settings columns + STI `type`
- External auth connectors.

### `roles`
- **PK:** `id`
- **Critical:** `name`, `permissions` (serialized), `builtin`, `assignable`, `position`, `issues_visibility`, `users_visibility`, `time_entries_visibility`, `settings`, `all_roles_managed`, `default_time_entry_activity_id`
- **FKs:** soft ref to enumeration activity via `default_time_entry_activity_id`

### `members`
- **PK:** `id`
- **Critical:** `user_id`, `project_id`, `mail_notification`
- **FKs:** `user_id` → `users.id`, `project_id` → `projects.id`
- **Unique:** `(user_id, project_id)`

### `member_roles`
- **PK:** `id`
- **Critical:** `member_id`, `role_id`, `inherited_from` (inheritance from parent project membership)
- **FKs:** `member_id` → `members.id`, `role_id` → `roles.id`

### `groups_users`
- **PK:** none (composite)
- **Columns:** `group_id`, `user_id` (both → `users.id`; group is STI)

### `roles_managed_roles`
- **PK:** none (composite)
- **Columns:** `role_id`, `managed_role_id` → `roles.id`

### `enabled_modules`
- **PK:** `id`
- **Critical:** `project_id`, `name` (module key: `issue_tracking`, `time_tracking`, `wiki`, …)
- **FKs:** `project_id` → `projects.id`

### OAuth (Doorkeeper) — core in 7.0.x
- `oauth_applications`, `oauth_access_grants`, `oauth_access_tokens`
- **FKs:** `resource_owner_id` → `users.id`; `application_id` → `oauth_applications.id`
- PKCE columns on grants (`code_challenge`, `code_challenge_method`)

---

## 2. Projects (P0) — 5 tables

### `projects` — **nested set**
- **PK:** `id`
- **Critical:** `name`, `identifier` (**unique**), `description`, `homepage`, `is_public`, `status`, `inherit_members`, `default_version_id`, `default_assigned_to_id`, `default_issue_query_id`
- **Tree:** `parent_id`, **`lft`**, **`rgt`** (indexed)
- **FKs:** `parent_id` → `projects.id`; defaults soft-ref versions/users/queries

### `projects_trackers`
- **PK:** none; unique `(project_id, tracker_id)`
- **FKs:** → `projects.id`, `trackers.id`

### `versions`
- **PK:** `id`
- **Critical:** `project_id`, `name`, `status`, `effective_date`, `sharing`, `wiki_page_title`
- **FKs:** `project_id` → `projects.id`

### `issue_categories`
- **PK:** `id`
- **Critical:** `project_id`, `name`, `assigned_to_id`
- **FKs:** `project_id` → `projects.id`; `assigned_to_id` → `users.id`

### `enumerations` (STI)
- **PK:** `id`
- **Critical:** `type` (IssuePriority, TimeEntryActivity, DocumentCategory, …), `name`, `position`, `is_default`, `active`, `project_id`, `parent_id`, `position_name`
- Priorities used by `issues.priority_id`; activities by `time_entries.activity_id`.

---

## 3. Issues core (P0) — 9 tables

### `trackers`
- **PK:** `id`
- **Critical:** `name`, `position`, `is_in_roadmap`, `default_status_id`, `fields_bits` (bitmask of core fields enabled), `description`, **`private_by_default`** (added in 7.0.x migration `20260520164915`)
- **FKs:** `default_status_id` → `issue_statuses.id`

### `issue_statuses`
- **PK:** `id`
- **Critical:** `name`, `is_closed`, `position`, `default_done_ratio`, `description`

### `issues` — **nested set (subtasks)**
- **PK:** `id`
- **Critical:** `project_id`, `tracker_id`, `subject`, `description`, `status_id`, `priority_id`, `author_id`, `assigned_to_id`, `category_id`, `fixed_version_id`, `start_date`, `due_date`, `done_ratio`, `estimated_hours`, `is_private`, `lock_version`, `closed_on`
- **Tree:** `parent_id`, **`root_id`**, **`lft`**, **`rgt`**
- **FKs:** project, tracker, status, priority (enumerations), author/assignee (users), category, fixed_version
- **Optimistic lock:** `lock_version`

### `issue_relations`
- **PK:** `id`
- **Critical:** `issue_from_id`, `issue_to_id`, `relation_type`, `delay`
- **Unique:** `(issue_from_id, issue_to_id)`

### `watchers` — polymorphic
- **PK:** `id`
- **Critical:** `watchable_type`, `watchable_id`, `user_id`

### `journals` — polymorphic history / notes
- **PK:** `id`
- **Critical:** `journalized_type`, `journalized_id`, `user_id`, `notes`, `private_notes`, `updated_by_id`, timestamps

### `journal_details`
- **PK:** `id`
- **Critical:** `journal_id`, `property`, `prop_key`, `old_value`, `value`
- Attribute/CF/attachment change rows for a journal.

### `workflows` — transitions **and** field permissions (STI `type`)
- **PK:** `id`
- **Critical:** `tracker_id`, `role_id`, `old_status_id`, `new_status_id`, `author`, `assignee`, `field_name`, `rule`, `type`
- Transition: old→new status for tracker×role (+ author/assignee flags).  
- Field permission rows: `field_name` + `rule` (readonly/required).

### `reactions` (core 7.x)
- **PK:** `id`
- **Critical:** `reactable_type`, `reactable_id`, `user_id`
- Polymorphic reactions on issues/journals/etc.

---

## 4. Custom fields (P0) — 6 tables

### `custom_fields` (STI via `type`)
- **PK:** `id`
- **Critical:** `type`, `name`, `field_format`, `possible_values`, `regexp`, `min_length`, `max_length`, `is_required`, `is_for_all`, `is_filter`, `searchable`, `editable`, `visible`, `multiple`, `default_value`, `format_store`, `description`, `position`
- Formats (app-layer): string, text, int, float, list, bool, date, user, version, attachment, link, enumeration, …

### Join scopes
- `custom_fields_trackers` `(custom_field_id, tracker_id)`
- `custom_fields_projects` `(custom_field_id, project_id)` — when not `is_for_all`
- `custom_fields_roles` `(custom_field_id, role_id)` — visibility by role when not globally visible

### `custom_values` — polymorphic storage
- **PK:** `id`
- **Critical:** `custom_field_id`, `customized_type`, `customized_id`, `value`
- Multiple values ⇒ multiple rows when `custom_fields.multiple`.

### `custom_field_enumerations`
- **PK:** `id`
- **Critical:** `custom_field_id`, `name`, `position`, `active`

---

## 5. Time / Attachments (P0) — 3 tables

### `time_entries`
- **PK:** `id`
- **Critical:** `project_id`, `issue_id`, `user_id`, `author_id`, `activity_id`, `hours`, `spent_on`, `comments`, `tyear`/`tmonth`/`tweek`
- **FKs:** project, issue (nullable), user, activity → `enumerations`

### `attachments` — polymorphic
- **PK:** `id`
- **Critical:** `container_type`, `container_id`, `filename`, `disk_filename`, `disk_directory`, `filesize`, `content_type`, `digest`, `downloads`, `author_id`, `description`

### `comments` — polymorphic
- **PK:** `id`
- **Critical:** `commented_type`, `commented_id`, `author_id`, `content`

---

## 6. Queries (P0) — 4 tables

### `queries` (STI `type`, e.g. IssueQuery)
- **PK:** `id`
- **Critical:** `name`, `filters`, `column_names`, `sort_criteria`, `group_by`, `options`, `user_id`, `project_id`, `visibility`, `description`
- Filter **operators and semantics are application logic** (P0b).

### `queries_roles`
- Visibility sharing: `(query_id, role_id)` when visibility = roles.

### `imports` / `import_items`
- CSV/import jobs (`type` STI); items track row → created object.

---

## 7. Wiki (P1) — 5 tables (listed, not deep-documented)

`wikis` (1:1 project), `wiki_pages` (`parent_id` tree), `wiki_contents`, `wiki_content_versions`, `wiki_redirects`.

---

## 8. SCM / Forums / News (P2) — 9 tables (listed)

`repositories`, `changesets`, `changes`, `changeset_parents`, `changesets_issues`, `boards`, `messages`, `news`, `documents`.

---

## 9. Settings / Webhooks (supporting)

- `settings` — global key/value (`name`, `value`)
- `webhooks` + `projects_webhooks` — outbound event hooks (7.x core)

---

## P0 Mermaid ERD

```mermaid
erDiagram
  users ||--o{ email_addresses : has
  users ||--o{ tokens : has
  users ||--o| user_preferences : has
  users ||--o{ members : "member user/group"
  users ||--o{ groups_users : "group or user"
  auth_sources ||--o{ users : authenticates

  roles ||--o{ member_roles : grants
  members ||--o{ member_roles : has
  members }o--|| projects : on
  roles ||--o{ roles_managed_roles : manages
  projects ||--o{ enabled_modules : enables

  projects ||--o{ projects : "parent_id tree + lft/rgt"
  projects ||--o{ projects_trackers : enables
  trackers ||--o{ projects_trackers : used_by
  projects ||--o{ versions : has
  projects ||--o{ issue_categories : has
  enumerations ||--o{ issues : "priority_id"
  enumerations ||--o{ time_entries : "activity_id"

  trackers ||--o{ issues : typed_as
  issue_statuses ||--o{ issues : status
  trackers }o--|| issue_statuses : "default_status_id"
  projects ||--o{ issues : contains
  users ||--o{ issues : "author/assignee"
  issues ||--o{ issues : "parent/root nested set"
  issues ||--o{ issue_relations : from
  issues ||--o{ issue_relations : to
  issues ||--o{ journals : "journalized"
  journals ||--o{ journal_details : details
  users ||--o{ watchers : watches
  issues ||--o{ watchers : "watchable"
  trackers ||--o{ workflows : rules
  roles ||--o{ workflows : rules
  issue_statuses ||--o{ workflows : "old/new"

  custom_fields ||--o{ custom_values : stores
  custom_fields ||--o{ custom_fields_trackers : scoped
  custom_fields ||--o{ custom_fields_projects : scoped
  custom_fields ||--o{ custom_fields_roles : visible_to
  custom_fields ||--o{ custom_field_enumerations : options
  trackers ||--o{ custom_fields_trackers : scoped
  issues ||--o{ custom_values : "customized"

  projects ||--o{ time_entries : logged_on
  issues ||--o{ time_entries : logged_on
  users ||--o{ time_entries : spent_by
  issues ||--o{ attachments : "container"
  projects ||--o{ attachments : "container"

  users ||--o{ queries : owns
  projects ||--o{ queries : scoped
  queries ||--o{ queries_roles : shared_with
  roles ||--o{ queries_roles : can_see

  users {
    int id PK
    string login
    string type "STI User/Group/..."
    boolean admin
    int status
  }
  projects {
    int id PK
    string identifier UK
    int parent_id
    int lft
    int rgt
    boolean is_public
  }
  issues {
    int id PK
    int project_id FK
    int tracker_id FK
    int status_id FK
    int priority_id FK
    int parent_id
    int root_id
    int lft
    int rgt
    string subject
  }
  roles {
    int id PK
    text permissions "serialized"
    string issues_visibility
  }
  members {
    int id PK
    int user_id FK
    int project_id FK
  }
  custom_fields {
    int id PK
    string type "STI"
    string field_format
    boolean multiple
  }
  custom_values {
    int id PK
    int custom_field_id FK
    string customized_type
    int customized_id
    text value
  }
  workflows {
    int id PK
    int tracker_id FK
    int role_id FK
    int old_status_id FK
    int new_status_id FK
    string type "STI transition/permission"
    string field_name
  }
  queries {
    int id PK
    text filters
    text column_names
    int visibility
    string type "STI"
  }
  journals {
    int id PK
    string journalized_type
    int journalized_id
    text notes
  }
  attachments {
    int id PK
    string container_type
    int container_id
    string disk_filename
  }
  time_entries {
    int id PK
    int project_id FK
    int issue_id FK
    float hours
    date spent_on
  }
```

---

## Gaps / follow-ups

| Item | Status |
| --- | --- |
| schema.rb for 7.0.1 under `sources/` | Done (regenerated dump; upstream does not commit it) |
| Plugin tables | None in core dump; RedmineShop plugins deferred |
| Full CF format matrix | **P0b** |
| Query operator matrix | **P0b** |
| Permission name list + workflow field rules matrix | **P0c** |
| DB-level FK constraints | Redmine historically relies on app-level FKs; dump may show few `add_foreign_key` except newer OAuth tables — treat logical FKs above as source of truth for Eloquent |
| Adapter differences | Dump from SQLite; Postgres/MySQL types/indexes may differ slightly (e.g. `lower(login)` expression index) — validate on target DB in P1 |

---

## Source path checklist

| Artifact | Path |
| --- | --- |
| Schema dump | `docs/sources/redmine-7.0.1-schema.rb` |
| This inventory | `docs/schema-inventory.md` |

---

## Laramine migration notes (pin 7.0.1)

Migrations in this repository create the **P0** tables only (41). Wiki (P1), SCM / forums / news (P2), and `settings` / `webhooks` / `projects_webhooks` are omitted.

PHPUnit and GitHub Actions apply these migrations on **MySQL 8**. SQLite is an optional local smoke path and is not the authoritative test database.

Eloquent models map the P0 tables. Polymorphic relations are declared, but there is no morph map yet, so Eloquent would persist PHP class names. Rows written with an explicit `*_type` string (for example `Issue`) keep that string. Do not ETL polymorphic type columns through Eloquent until the map is pinned. `type` STI columns are plain strings; PHP subclasses are not mapped.

### Role permission codec

`roles.permissions` and `roles.settings` are unchanged text columns. Laramine writes `permissions` as a JSON array of name strings, for example `["view_issues","add_issues"]`. The reader also accepts a Redmine YAML symbol list (`- :view_issues`) so an ETL load can be interpreted before it is rewritten. Empty or unrecognized text grants no non-public permissions. Public permission names are implied and do not need to be stored. `roles.settings` is JSON when written through the model; non-JSON text is ignored at runtime. Per-tracker masks inside settings are not evaluated. Details and seeded roles: [domain.md](domain.md).

### Intentional adapter differences

| Topic | Dump / Rails | Laramine |
| --- | --- | --- |
| Primary keys | Signed 32-bit integer | `$table->integer('id', autoIncrement: true)` (signed integer, not Laravel `bigIncrements`) |
| `attachments.filesize` (`limit: 8`) | 8-byte integer | MySQL `BIGINT`. SQLite stores integer affinity |
| String lengths | `varchar(n)` | Kept on MySQL. SQLite's Laravel grammar emits `varchar` without a length |
| Datetime precision | `precision: nil` on most Redmine columns; OAuth and `reactions` use the adapter default (fractional seconds) | `dateTime` precision 0, except OAuth and `reactions` at precision 6. SQLite ignores fractional precision |
| `float` | `t.float` | SQL `FLOAT` with no precision argument (Laravel's default precision of 53 is not used) |
| `index_users_on_lower_login` | Expression index on lower(login) | Same expression on SQLite. MySQL 8 and MariaDB use a parenthesized functional index so the server accepts it |
| Booleans | Boolean | Laravel boolean (`tinyint(1)` on MySQL) |
| Database foreign keys | Dump declares only the four OAuth foreign keys | Those four, plus non-polymorphic foreign keys whose column is nullable or required without a `0` default. Columns with `null: false, default: 0` stay unconstrained so a Redmine `0` sentinel still loads. Polymorphic ids have no database foreign key |
| Extra indexes | Not in the dump | MySQL 8 InnoDB adds a supporting index named `*_foreign` when a foreign key column is not already indexed. Confirmed on 8.0 for `groups_users.user_id`, `roles_managed_roles.managed_role_id`, `custom_fields_roles.role_id`, `queries_roles.role_id`, `time_entries.author_id`, `custom_field_enumerations.custom_field_id`, `oauth_access_grants.resource_owner_id`, `enumerations.parent_id`, `imports.user_id`, `journals.updated_by_id`, `projects.parent_id`, `projects.default_assigned_to_id`, `projects.default_version_id`, `projects.default_issue_query_id`, `roles.default_time_entry_activity_id`, and `trackers.default_status_id` |

Laravel framework tables stay beside this schema: `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`. `sessions.user_id` is a nullable signed integer so it matches `users.id`. `auth_sources` is created in the same migration as `users` so `auth_source_id` can reference it without rebuilding `users` (SQLite drops the `lower(login)` expression when that table is rebuilt).
