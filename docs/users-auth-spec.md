# Users and authentication spec

**Status: founder lock 2026-10-07. Phase 1 web sign-in is implemented. NOT VERIFIED.** This is not a 0.1 tag and it is not production-ready. A green feature test is Laramine behavior. The parity row stays **NOT VERIFIED** until a `tests/Parity` comparison against a pinned Redmine 7.0.1 fixture is recorded in [parity-checklist.md](parity-checklist.md).

Column lists stay in [schema-inventory.md](schema-inventory.md) (section “1. Identity / ACL”) and in the structure dump [sources/redmine-7.0.1-schema.rb](sources/redmine-7.0.1-schema.rb). This file does not copy that inventory and does not copy Redmine Ruby.

## Founder lock

Locked on **2026-10-07**.

- **Code structure:** Laravel idioms. `User` stays `Authenticatable`. The web guard is the session guard in `config/auth.php`. Sign-in uses a form request, the session guard, and middleware. Do not invent a second auth framework beside that.
- **Product behavior:** Redmine 7.0.1 users and auth semantics, clean-room (behavior and schema only).

The sixteen decisions below are closed. A later slice follows them. If a new product question shows up that this file does not answer, stop and record it here before coding it.

## Non-goals

- No parity row set to **VERIFIED**. No `tests/Parity` comparison is added by the lock or by Phase 1.
- No 0.1 tag. The ACL/workflow smoke is not that tag. See [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md).
- No Sanctum, Fortify, Jetstream, or Passport. The `oauth_*` tables stay. They are not replaced by a personal-access-token package.
- No bcrypt-only password column. `hashed_password` stays `varchar(40)` and `salt` stays `varchar(64)`.
- No use of the email-keyed `password_reset_tokens` broker as the product recovery path.
- No committed administrator password and no treatment of the factory placeholder as a secret.
- No change to journal, custom-field, or query behavior in Phase 1.

## Locked decisions

1. **Password store.** Keep `hashed_password` and `salt`. The digest is the SHA-1 hex (40 lowercase characters) of the salt concatenated with the SHA-1 hex of the clear password. New salts are 32 lowercase hex characters (16 random bytes). Writes always store a fresh salt in that form. A blank salt is checked with an empty prefix, which is the same concatenation. The check was matched to published 7.0.1 sample digests; those rows are not copied into this tree. The factory’s forty `0` characters are not a password and never verify. When `auth_source_id` is set, the local digest is not the credential (see decision 7). There is no dual-write to bcrypt.

2. **Login identifier.** Sign-in accepts `users.login` or any `email_addresses.address` on that account. Comparison is trimmed and case-insensitive (`LOWER`, matching the `lower(login)` index). A login match wins over an address match. `is_default` does not choose which address may sign in; it marks the address shown as the account mail. Zero matches, or more than one login or more than one user for an address, do not sign in.

3. **Session and recovery store.** The web session is Laravel’s session guard. The default session driver is `database` (`sessions`). Phase 1 does not write a `tokens` row. Product recovery, API keys, and feed keys use `tokens`, not `password_reset_tokens`. Named actions in 7.0.1, closing the inventory ellipsis:
   - `api` — one REST key, no expiry
   - `feeds` — one Atom key, no expiry
   - `recovery` — one password-recovery token, short-lived (about one day), looked up through `email_addresses`
   - `register` — one account-activation token, same short life
   - `session` — up to ten web-session tokens (idle lifetime is a later phase)
   - `autologin` — up to ten remember-me tokens
   - `twofa_backup_code` — up to ten backup codes
   Phase 1 ships only the Laravel session. The `session` token action is still the later Redmine-shaped session-token store. It is not replaced by deleting the column or the table.

4. **Status integers.** Only these codes are named. Sign-in requires `1`.

   | Code | Name | Sign-in |
   | --- | --- | --- |
   | 0 | anonymous | no |
   | 1 | active | yes, when the other gates in this file also pass |
   | 2 | registered | no (not activated yet) |
   | 3 | locked | no |

   Any other integer is not active and cannot sign in. `PermissionService::isLoggedIn` still requires a non-null row, a type other than `AnonymousUser`, and status `1`. An inactive admin is not logged in.

5. **Guest.** A null actor stays the guest for permission checks (Anonymous builtin role). A persisted `AnonymousUser` row is the guest principal when a `user_id` must be stored for an unauthenticated actor: issue author, journal user, watcher, time entry, and attachment author. That row, when present, uses `type = AnonymousUser`, status `0`, and an empty login. Phase 1 does not create it. The row cannot sign in, even if a digest were stored on it. Callers that need the id and do not find the row fail closed until a later slice seeds it. Group rows (`type = Group`) are membership principals, not sign-in accounts.

6. **Registration and `must_change_passwd`.** In scope for Redmine-like behavior, phased (Phase 2). Self-registration follows the setting values disabled (`0`), email activation (`1`), manual activation (`2`), and automatic activation (`3`). Activation consumes a `register` token and moves status from `2` to `1`. `must_change_passwd` forces a password change after the password check succeeds, before the rest of the app is usable. `passwd_changed_on` records the change. Phase 1 does not register users and does not enforce `must_change_passwd`.

7. **`auth_sources` / LDAP.** In scope, phased (Phase 3). A user with `auth_source_id` set is checked against that source, not against `hashed_password`. On-the-fly registration stays a property of the source. Phase 1 fails closed: those accounts cannot open a session, and the local digest is not accepted for them.

8. **Two-factor columns.** In scope, phased (Phase 4). Columns stay `twofa_required`, `twofa_scheme`, `twofa_totp_key`, and `twofa_totp_last_used_at`. Backup codes are `tokens` rows with action `twofa_backup_code`. Phase 1 does not check a second factor. If `twofa_required` is true or `twofa_scheme` is non-empty, Phase 1 does not open a session.

9. **OAuth tables.** In scope as Redmine/Doorkeeper-shaped behavior, phased (Phase 5). Later work uses Laravel-style services on `oauth_applications`, `oauth_access_grants`, and `oauth_access_tokens` (including PKCE columns). The resource owner is `users.id`. Do not drop those tables for a Sanctum-only design.

10. **API and feed tokens.** In scope, phased (Phase 6), via `tokens` actions `api` and `feeds`. Phase 1 does not issue or accept them.

11. **`users_visibility`.** Legal values are `all` and `members_of_visible_projects`. The migration default is `members_of_visible_projects`. Enforcement is phased with the user directory (Phase 7). Phase 1 does not read the column. It remains an open item on the ACL gate.

12. **Account administration.** `users.admin` on an active user is the account-admin flag: create, edit, lock, unlock, and delete accounts, and group membership outside project roles. `manage_members` stays project membership only. Deleting an account removes personal rows (preferences, tokens, memberships, private queries) and reassigns public authorship. That workflow is Phase 8. Phase 1 does not add it. The existing admin bypass of project permission checks is unchanged.

13. **Mail notification and preferences.** In scope, phased (Phase 9). `users.mail_notification` values are `all`, `selected`, `only_my_events`, `only_assigned`, `only_owner`, and `none`. `members.mail_notification` stays a boolean. `user_preferences` stores `hide_mail`, `time_zone`, and `others`. Mail delivery stays outside this slice. Phase 1 does not read or encode these. The factory’s empty `mail_notification` is not one of the six values and is left as-is.

14. **`UserQuery`.** Running a saved user query is Phase 7. Storing the stub with empty filters is already allowed and is not a user directory. See [queries.md](queries.md).

15. **Framework skeleton.** `User` keeps extending Laravel’s authenticatable user. `getAuthPasswordName()` stays `hashed_password`. There is no remember-token column (`getRememberTokenName()` stays empty); remember-me is the later `autologin` token. `config/auth.php` is in real use: guard `web` is the session driver, and the user provider driver is `redmine` (login or email lookup, digest check, Phase 1 gates). Framework `Auth::attempt` and logout are allowed. `password_reset_tokens` remains in the config file so the framework boots, and it is not the product recovery path.

16. **Seeded `admin` login.** `DatabaseSeeder` still creates `login = admin` with `admin = false` and the placeholder digest. That row cannot sign in. A real administrator seed may set `admin = true` and a sealed digest only when an operator supplies the credential from outside the repository. No password is committed. The placeholder is not a backdoor.

## Phases

| Phase | Scope | State |
| --- | --- | --- |
| 1 | Redmine-compatible digest, session sign-in and sign-out, active-status gate, generic failure, `last_login_on` | Landed |
| 2 | Registration, `register` token, password change, `must_change_passwd`, `recovery` token via `email_addresses` | Later |
| 3 | `auth_sources` / LDAP, including on-the-fly registration | Later |
| 4 | Two-factor scheme, TOTP, backup codes | Later |
| 5 | OAuth services on the existing `oauth_*` tables | Later |
| 6 | API and feed tokens | Later |
| 7 | `users_visibility`, user directory, running `UserQuery` | Later |
| 8 | Account administration under `users.admin` | Later |
| 9 | Mail-notification values and the `user_preferences.others` codec | Later |
| 10 | `session` and `autologin` token rows; operator-supplied administrator seed | Later |

## Phase 1 behavior

`App\Domain\Auth\RedminePassword` seals and checks the digest. `App\Domain\Auth\CredentialChecker` resolves the identifier and returns a decision. `App\Auth\RedmineUserProvider` is the `redmine` user provider. It does not ask Laravel’s hasher to replace `hashed_password`.

HTTP, session middleware. Sign-in does not use Inertia. `GET /` is the Inertia health smoke page and is not a sign-in screen.

| Method and path | Behavior |
| --- | --- |
| `GET /login` | Blade sign-in form |
| `POST /login` | Session sign-in. JSON returns the id and login. A form posts back to `/` on success. |
| `POST /logout` | Invalidates the session |
| `GET /custom-fields/attachments/{id}` | Authorized download of a custom-field attachment. Uses the session user when one is present. A guest is allowed only when the host is visible to Anonymous. Does not sign anyone in. |
| `GET /custom-fields/links/{id}` | Authorized resolution of a link custom value. Same actor rule. Does not request the remote URL. |

Failed sign-in, unknown identifier, wrong password, inactive status, anonymous or group type, external auth, and two-factor all return the same message: “Invalid user or password.” The domain decision distinguishes them for tests. Empty login or password is a validation error. A signed-in user whose row no longer passes the session gate is signed out on the next request.

`last_login_on` is set when the session is created. No `tokens` row is written.

## Inventory (pointer, not a second schema)

| Concept | Redmine 7.0.1 store | Notes |
| --- | --- | --- |
| Account row | `users` | STI `User`, `Group`, `AnonymousUser`. Status codes are decision 4. |
| Mail | `email_addresses` | No `email` column on `users`. Sign-in may use any address (decision 2). |
| Action tokens | `tokens` | Actions are decision 3. Phase 1 does not issue them. |
| Preferences | `user_preferences` | Phase 9. `others` is still undecoded text. |
| External auth | `auth_sources` | Phase 3. Phase 1 denies these users. |
| Group membership | `groups_users` | Project role expansion stays in [domain.md](domain.md). |
| Project membership | `members`, `member_roles` | `manage_members` is not account admin. |
| Who can see which users | `roles.users_visibility` | Decision 11. Not applied. |
| OAuth | `oauth_applications`, `oauth_access_grants`, `oauth_access_tokens` | Decision 9. No authorization server yet. |
| Watchers | `watchers.user_id` | A user id, not a credential. |

Laravel `sessions` is the Phase 1 web session store. `sessions.user_id` references `users.id`. `password_reset_tokens` is unused by the product.

## Intentional differences (Phase 1)

- The web session row is Laravel `sessions`, not `tokens.action = session`.
- Accounts with `auth_source_id`, `twofa_required`, or a `twofa_scheme` cannot sign in yet. Redmine would continue into LDAP or the second factor.
- `must_change_passwd` is stored and not enforced.
- The forty-zero placeholder never verifies, even if a digest collided with it.
- Posted passwords are not trimmed. Identifiers are trimmed.
- HTTP failures use one message so the response does not reveal which gate failed.

## What would mark the parity row verified

Not this lock, and not Phase 1. The row stays **NOT VERIFIED** until a test under `tests/Parity` compares this tree to a pinned Redmine 7.0.1 fixture or recorded result, and the checklist evidence cites that test. Passing the ACL/workflow smoke does not close users and auth.

## Checklist links

| Checklist | Users / auth status |
| --- | --- |
| [parity-checklist.md](parity-checklist.md) | **NOT VERIFIED.** Decisions locked 2026-10-07. Phase 1 is Laramine session sign-in. No Redmine comparison. Not a 0.1 tag. |
| [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md) | Open. `users_visibility` is still not applied. Not a 0.1 tag. |
| [QUALITY.md](../QUALITY.md) | No 0.1 tag. Phase 1 is not a production-ready claim. |
