# Users and authentication spec (0.1 path)

**Status: spec hole. NOT VERIFIED.** This file inventories the Redmine 7.0.1 users and auth surface that Laramine already stores, and the gaps a founder must close before any authentication implementation. It is not a 0.1 tag. It does not verify parity. Journals, custom fields, and queries stay **NOT VERIFIED**.

Column lists stay in [schema-inventory.md](schema-inventory.md) (section “1. Identity / ACL”) and in the structure dump [sources/redmine-7.0.1-schema.rb](sources/redmine-7.0.1-schema.rb). This file does not copy that inventory and does not copy Redmine Ruby.

## Non-goals

- No login, session establishment, password hashing, password reset, OAuth authorization server, LDAP bind, two-factor check, or user-administration workflow.
- No Sanctum, Fortify, Jetstream, Passport, or any other authentication package.
- No new user table. The Redmine `users` table is already migrated. Laravel `sessions` and `password_reset_tokens` are framework tables already called out in the schema inventory. This spec does not add another one.
- No parity row set to **VERIFIED**. No `tests/Parity` comparison is added here.
- No 0.1 tag, and no reading of the ACL/workflow smoke as that tag. See [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md).
- No change to journal, custom-field, or query behavior.

If a later slice needs a product choice that this file leaves open, stop and record the founder’s answer. Do not fill the blank in code.

## Inventory (pointer, not a second schema)

| Concept | Redmine 7.0.1 store | Where the columns are written down |
| --- | --- | --- |
| Account row | `users` | Schema inventory `users`. STI `type` names already used in `App\Models\User`: `User`, `Group`, `AnonymousUser`. |
| Mail | `email_addresses` | Schema inventory. `users` has no `email` column (`tests/Feature/P0SchemaTest.php`). |
| Action tokens | `tokens` (`action`, `value`) | Schema inventory names `session`, `api`, `feeds`, `recovery`, and an ellipsis. `App\Models\Token` repeats those four names. The ellipsis is not closed in this tree. |
| Preferences | `user_preferences` | Schema inventory. `others` is stored text. `App\Models\UserPreference` does not decode it. |
| External auth connector | `auth_sources` | Schema inventory. STI `type` plus host, bind, and attribute columns. No connector class. |
| Group membership of users | `groups_users` | Schema inventory. Group rows are `users` with `type = Group`. Project role expansion is [domain.md](domain.md), not this file. |
| Project membership | `members`, `member_roles` | Schema inventory and [domain.md](domain.md). `members.user_id` has no database foreign key (`tests/Feature/P0SchemaTest.php`), matching the inventory rule for `default: 0` columns. |
| Who can see which users | `roles.users_visibility` | Column only. Default in the migration is `members_of_visible_projects`. Not read by permission checks. |
| OAuth tables | `oauth_applications`, `oauth_access_grants`, `oauth_access_tokens` | Schema inventory “OAuth (Doorkeeper)”. Models exist. No authorization server. |
| Watchers | `watchers.user_id` | A user id on a watchable. Not a credential. |

Other P0 columns point at `users.id` (`issues.author_id`, `issues.assigned_to_id`, `journals.user_id`, `queries.user_id`, `time_entries.user_id`, `reactions.user_id`, `imports.user_id`, `projects.default_assigned_to_id`, and the foreign keys in `database/migrations/2026_08_26_100900_add_p0_foreign_keys.php`). Those are actors and assignees for slices that already exist. They are not authentication.

`email_addresses.user_id` has a database foreign key. `tokens.user_id` and `user_preferences.user_id` use default `0` and are not in that foreign-key migration.

Laravel framework tables `sessions` and `password_reset_tokens` sit beside the Redmine schema. They are not Redmine tables. `sessions.user_id` is a nullable integer foreign key to `users.id`. `config/auth.php` points the password broker at `password_reset_tokens`. Nothing in `routes/web.php` writes either table.

## What Laramine already has

These rows exist so other slices can name a user. They are not a signed-in account.

| Piece | Present now | Absent |
| --- | --- | --- |
| `users` and `auth_sources` migrations | `database/migrations/0001_01_01_000000_create_users_table.php` | Password check, login, last-login update |
| `email_addresses`, `tokens`, `user_preferences`, `roles` | `database/migrations/2026_08_26_100100_create_identity_tables.php` | Mail rows from the factory or seeder, token issue/consume, preference codec |
| OAuth column layout | `database/migrations/2026_08_26_100800_create_oauth_tables.php` and `App\Models\Oauth*` | Grant, token, or application services |
| Eloquent `User` | STI constants, `status === 1` as `isActive()`, relations to mail, tokens, preference, memberships, groups | Subclasses for Group and AnonymousUser. Mass assignment is open (`$guarded = ['id']`). |
| Password column hook | `getAuthPasswordName()` returns `hashed_password`. `getRememberTokenName()` returns `''`. The model extends Laravel’s authenticatable user. | Any call that verifies a password. The factory writes forty `0` characters and says the hash-and-salt algorithm is not applied. |
| ACL “logged in” | `PermissionService::isLoggedIn`: a non-null row, `type` other than `AnonymousUser`, and `status === 1`. Null uses the Anonymous builtin role. | A session, a guard, or a request user. Callers pass the `User` in. |
| Admin bypass | `users.admin` and `status === 1` | A screen or service that sets `admin` |
| Inactive account | Any `status` other than `1` is treated as logged out, including admins ([domain.md](domain.md)) | Names or workflows for any status integer other than `1` |
| Sample user | `DatabaseSeeder` creates `login = admin` via `UserFactory` after `DefaultAccessSeeder` | `admin` is false (factory default). No email row. The password is the placeholder. This is not an account that can sign in. |
| Fixture user | `Tests\Support\DomainFixture` creates one active `User` and can attach a project role | Same placeholder password. Used for membership and issue tests. |
| Membership | `MembershipService` assigns project roles, expands groups, and cascades `inherit_members` | Account create, lock, or delete |
| User custom-field value | Format `user` stores an active `type = User` id, and can require project membership ([custom-fields.md](custom-fields.md)) | User administration |
| `UserQuery` | STI stub. Empty filters can be stored and are not run ([queries.md](queries.md)) | A user list |
| HTTP | `routes/web.php` returns the welcome view | Login, account, or API routes |
| Permission names | Catalog includes `view_members` and `manage_members` (project membership) and `log_time_for_other_users` | No catalog name for creating or editing accounts. Whether account admin is only `users.admin` is open below. |

`config/auth.php` is the framework skeleton: session guard `web`, eloquent provider `App\Models\User`, password broker on `password_reset_tokens`. That file is not a founder decision to use those mechanisms.

## Open decisions (no default)

Each item stays unanswered until the founder writes the choice. This spec does not pick one.

1. **Password store.** `hashed_password` is `varchar(40)` and `salt` is `varchar(64)` in the 7.0.1 dump. The factory does not hash. Whether 0.1 checks a password against those columns, and with which algorithm, is open. Laravel’s default hasher is not selected.
2. **Login identifier.** `users.login` is required in the dump sense (`null: false`). Mail is only on `email_addresses` (`address`, `is_default`, `notify`). Whether a person signs in with `login`, with the default address, or with either, is open.
3. **Session and recovery store.** Redmine-shaped `tokens.action` versus Laravel `sessions` and `password_reset_tokens`. The names already written in this tree are `session`, `api`, `feeds`, and `recovery`. Further `action` values are not listed. Which store 0.1 uses, and which action strings are in scope, is open. Do not treat the Laravel password broker as the product default: its table is keyed by email, and this schema keeps mail off `users`.
4. **Status integers other than 1.** Code defines `User::STATUS_ACTIVE = 1` only. Other integers are “not logged in” for ACL and nothing else. Registration, lock, and anonymous status codes are not named here.
5. **Guest shape.** A null `User` and a row with `type = AnonymousUser` both fail `isLoggedIn`. Whether a persisted anonymous row is required is open.
6. **Registration and forced password change.** `must_change_passwd` and `passwd_changed_on` exist. No registration or change-password flow exists. Whether 0.1 includes either is open.
7. **External authentication.** `auth_sources` is an LDAP-shaped table (`onthefly_register`, host, TLS, attribute names, STI `type`). No bind client exists. Whether any auth source is in 0.1 is open.
8. **Two-factor.** `twofa_required`, `twofa_scheme`, `twofa_totp_key`, and `twofa_totp_last_used_at` are columns. No scheme is chosen and no check exists. Whether 0.1 includes two-factor is open.
9. **OAuth.** The three OAuth tables are migrated for ETL shape. Whether 0.1 runs an authorization server, only preserves rows, or ignores them is open. Do not substitute Sanctum, Passport, or Fortify.
10. **API and feed credentials.** `tokens` is not issued. Whether an API key or a feed token is in 0.1 is open.
11. **`users_visibility`.** The column is stored and not applied. The set of legal values is not specified in this spec. Enforcement waits on a founder decision and a later slice. It is already an open item on the ACL gate.
12. **Account administration.** Project `manage_members` assigns a role on a project. It does not create a user. Who may create, edit, lock, or delete an account, and whether that is only `users.admin`, is open. Group membership outside project-role expansion is the same gap.
13. **Mail notification and preferences.** `users.mail_notification`, `members.mail_notification`, `user_preferences.hide_mail`, `time_zone`, and `others` are stored. Mail delivery is outside the P0 slice. Whether 0.1 reads any of these is open. `others` has no codec.
14. **`UserQuery`.** Running a saved user query is open. Storing the stub is already allowed and is not a user directory.
15. **Framework auth skeleton.** Whether `User` keeps extending Laravel’s authenticatable base, and whether `config/auth.php` stays as an unused skeleton, is open. Until that decision, do not call framework login or password-reset APIs against these rows. The placeholder hash must not be treated as a working secret.
16. **Seeded `admin` login.** The seeder name is not an administrator flag. Whether a real administrator row is seeded, and with what credential, is open. Do not turn the current factory row into a backdoor.

## What would move this off “spec hole”

Not now. A later change may implement authentication only after the founder has answered the decisions that slice needs. The parity row stays **NOT VERIFIED** until a `tests/Parity` comparison against a pinned Redmine 7.0.1 fixture records evidence in [parity-checklist.md](parity-checklist.md). A green feature test of Laramine’s own rules would still leave the row **NOT VERIFIED**.

That later slice is not authorized by this file. Passing the ACL/workflow smoke does not close it.

## Checklist links

| Checklist | Users / auth status |
| --- | --- |
| [parity-checklist.md](parity-checklist.md) | **NOT VERIFIED.** Spec hole. No Redmine comparison. |
| [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md) | Open. Not a 0.1 tag. |
| [QUALITY.md](../QUALITY.md) 0.1 path | Spec hole. Founder unlock required before implementation. |
