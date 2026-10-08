# Users and authentication spec

**Status: founder lock 2026-10-07. Phases 1–10 are implemented.** The Phase 2 checklist row is **VERIFIED** by `tests/Parity/UsersAuthParityTest.php`. The later-phase sub-rows are **VERIFIED** by `tests/Parity/UsersAuthGapParityTest.php`. Outbound mail is **VERIFIED** by `tests/Parity/NotificationParityTest.php`. Activity for issues, journals, and time entries is **VERIFIED** by `tests/Parity/ActivityParityTest.php`. OpenID Connect is **N/A**. The full REST API row is **VERIFIED** by `tests/Parity/RestApiParityTest.php`. Live LDAP stays **NOT VERIFIED**. This is not a 0.1 tag and it is not production-ready. A green feature test by itself is Laramine behavior. `users_visibility` is compared on the identity row.

Column lists stay in [schema-inventory.md](schema-inventory.md) (section “1. Identity / ACL”) and in the structure dump [sources/redmine-7.0.1-schema.rb](sources/redmine-7.0.1-schema.rb). This file does not copy that inventory and does not copy Redmine Ruby.

## Founder lock

Locked on **2026-10-07**.

- **Code structure:** Laravel idioms. `User` stays `Authenticatable`. The web guard is the session guard in `config/auth.php`. Sign-in uses a form request, the session guard, and middleware. Do not invent a second auth framework beside that.
- **Product behavior:** Redmine 7.0.1 users and auth semantics, clean-room (behavior and schema only).

The sixteen decisions below are closed. A later slice follows them. If a new product question shows up that this file does not answer, stop and record it here before coding it.

## Non-goals

- The lock itself did not mark a parity row **VERIFIED**. Phase 2 added the comparison named in [parity-checklist.md](parity-checklist.md). That row is not a 0.1 tag.
- No 0.1 tag. The ACL/workflow smoke is not that tag. See [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md).
- No Sanctum, Fortify, Jetstream, or Passport. The `oauth_*` tables stay. They are not replaced by a personal-access-token package.
- No bcrypt-only password column. `hashed_password` stays `varchar(40)` and `salt` stays `varchar(64)`.
- No use of the email-keyed `password_reset_tokens` broker as the product recovery path.
- No committed administrator password and no treatment of the factory placeholder as a secret.
- No change to journal, custom-field, or query behavior in Phase 1.

## Locked decisions

1. **Password store.** Keep `hashed_password` and `salt`. The digest is the SHA-1 hex (40 lowercase characters) of the salt concatenated with the SHA-1 hex of the clear password. New salts are 32 lowercase hex characters (16 random bytes). Writes always store a fresh salt in that form. A blank salt is checked with an empty prefix, which is the same concatenation. The check was matched to published 7.0.1 sample digests; those rows are not copied into this tree. The factory’s forty `0` characters are not a password and never verify. When `auth_source_id` is set, the local digest is not the credential (see decision 7). There is no dual-write to bcrypt.

2. **Login identifier.** Sign-in accepts `users.login` or any `email_addresses.address` on that account. Comparison is trimmed and case-insensitive (`LOWER`, matching the `lower(login)` index). A login match wins over an address match. `is_default` does not choose which address may sign in; it marks the address shown as the account mail. Zero matches, or more than one login or more than one user for an address, do not sign in.

3. **Session and recovery store.** The web session is Laravel’s session guard. The default session driver is `database` (`sessions`). Product recovery, API keys, feed keys, session rows, and autologin rows use `tokens`, not `password_reset_tokens`. Named actions in 7.0.1, closing the inventory ellipsis:
   - `api` — one REST key, no expiry
   - `feeds` — one Atom key, no expiry
   - `recovery` — one password-recovery token, short-lived (about one day), looked up through `email_addresses`
   - `register` — one account-activation token, same short life
   - `session` — up to ten web-session tokens. `session_lifetime` and `session_timeout` are minute caps; `0` disables each one
   - `autologin` — up to ten remember-me tokens, only when `autologin` is 1, 7, 30, or 365 days
   - `twofa_backup_code` — up to ten backup codes
   Phase 2 writes `recovery` and `register`. Phase 10 writes `session` on sign-in and `autologin` when the account asks to be remembered. The Laravel `sessions` row stays.

4. **Status integers.** Only these codes are named. Sign-in requires `1`.

   | Code | Name | Sign-in |
   | --- | --- | --- |
   | 0 | anonymous | no |
   | 1 | active | yes, when the other gates in this file also pass |
   | 2 | registered | no (not activated yet) |
   | 3 | locked | no |

   Any other integer is not active and cannot sign in. `PermissionService::isLoggedIn` still requires a non-null row, a type other than `AnonymousUser`, and status `1`. An inactive admin is not logged in.

5. **Guest.** A null actor stays the guest for permission checks (Anonymous builtin role). A persisted `AnonymousUser` row is the guest principal when a `user_id` must be stored for an unauthenticated actor: issue author, journal user, watcher, time entry, and attachment author. That row, when present, uses `type = AnonymousUser`, status `0`, and an empty login. The seeder does not create it. The row cannot sign in, even if a digest were stored on it. Deleting an account that still has authorship fails closed until that row exists. Group rows (`type = Group`) are membership principals, not sign-in accounts.

6. **Registration and `must_change_passwd`.** Self-registration follows the setting values disabled (`0`), email activation (`1`), manual activation (`2`), and automatic activation (`3`). A missing value is manual (`2`). Activation consumes a `register` token and moves status from `2` to `1`. `must_change_passwd` forces a password change after the password check succeeds, before the rest of the app is usable. `passwd_changed_on` records the change. Phase 2 enforces this. An active administrator can activate a status `2` account.

7. **`auth_sources` / LDAP.** A user with `auth_source_id` set is checked against that source, not against `hashed_password`. On-the-fly registration stays a property of the source. A blank host fails closed. The tested adapter is in memory. A live directory is not implemented.

8. **Two-factor columns.** Columns stay `twofa_required`, `twofa_scheme`, `twofa_totp_key`, and `twofa_totp_last_used_at`. Backup codes are `tokens` rows with action `twofa_backup_code`. Setting `twofa` selects disabled, optional, required for administrators, or required for every account. A stored scheme is ignored while the setting is disabled. The challenge is not a session until the code succeeds. API keys and bearer tokens skip that challenge. HTTP basic does not.

9. **OAuth tables.** In scope as Redmine/Doorkeeper-shaped behavior, phased (Phase 5). Later work uses Laravel-style services on `oauth_applications`, `oauth_access_grants`, and `oauth_access_tokens` (including PKCE columns). The resource owner is `users.id`. Do not drop those tables for a Sanctum-only design.

10. **API and feed tokens.** Actions `api` and `feeds` each keep one row and do not expire. REST authentication is off until `rest_api_enabled` is set. The probe is `GET /users/current.json`. The feed probe is `GET /my.atom`. An API key does not open the feed, and a feed key does not open REST.

11. **`users_visibility`.** Legal values are `all` and `members_of_visible_projects`. The migration default is `members_of_visible_projects`. `UserVisibility` applies the column, including a new issue assignee and the user directory. Phase 2 sign-in does not read it. `UserQuery` runs inside that scope.

12. **Account administration.** `users.admin` on an active user is the account-admin flag: create, edit, lock, unlock, activate, and delete accounts, and group membership outside project roles. `manage_members` stays project membership only. Deleting an account removes personal rows and reassigns public authorship to `AnonymousUser`, or fails closed when that row is missing and authorship exists. The existing admin bypass of project permission checks is unchanged.

13. **Mail notification and preferences.** `users.mail_notification` values are `all`, `selected`, `only_my_events`, `only_assigned`, `only_owner`, and `none`. `members.mail_notification` stays a boolean. `user_preferences` stores `hide_mail`, `time_zone`, and `others`. `others` is JSON. Delivery of account and issue mail is in [domain.md](domain.md) and is compared on the outbound mail checklist row. The factory’s empty `mail_notification` is not one of the six values and is left as-is. A blank value does not receive mail.

14. **`UserQuery`.** Running a saved user query is Phase 7. Storing the stub with empty filters is already allowed and is not a user directory. See [queries.md](queries.md).

15. **Framework skeleton.** `User` keeps extending Laravel’s authenticatable user. `getAuthPasswordName()` stays `hashed_password`. There is no remember-token column (`getRememberTokenName()` stays empty); remember-me is the later `autologin` token. `config/auth.php` is in real use: guard `web` is the session driver, and the user provider driver is `redmine` (login or email lookup, digest check, Phase 1 gates). Framework `Auth::attempt` and logout are allowed. `password_reset_tokens` remains in the config file so the framework boots, and it is not the product recovery path.

16. **Seeded `admin` login.** `DatabaseSeeder` calls `AdministratorSeed`. Without `LARAMINE_ADMIN_PASSWORD` the row keeps `login = admin`, `admin = false`, and the placeholder digest, so it cannot sign in. A password supplied from the environment seals `hashed_password` and sets `admin = true`. No password is committed. The placeholder is not a backdoor.

## Phases

| Phase | Scope | State |
| --- | --- | --- |
| 1 | Redmine-compatible digest, session sign-in and sign-out, active-status gate, generic failure, `last_login_on` | Landed |
| 2 | Registration, `register` token, password change, `must_change_passwd`, `recovery` token via `email_addresses`, status notices | Landed. Compared by `tests/Parity/UsersAuthParityTest.php`. Account mail is compared on the outbound mail row. |
| 3 | `auth_sources` / LDAP, including on-the-fly registration | Landed. `MemoryLdapDirectory` is the tested adapter. A live directory is not implemented. |
| 4 | Two-factor scheme, TOTP, backup codes | Landed. Setting `twofa` is `0` disabled, `1` optional, `2` required for administrators, `3` required. A disabled setting ignores a stored scheme. |
| 5 | OAuth services on the existing `oauth_*` tables | Landed for the authorization-code grant, PKCE (`S256` and `plain`), and refresh-token rotation. OpenID Connect and an external identity provider are not in 7.0.1 core and are not implemented. |
| 6 | API and feed tokens | Landed. `GET /users/current.json` accepts `X-Redmine-API-Key`, `key`, HTTP basic, and a bearer access token when `rest_api_enabled` is on. `GET /my.atom` accepts a `feeds` key and renders that account's activity feed. The activity comparison is the Activity checklist row. The full REST API is the separate checklist row. |
| 7 | User directory, running `UserQuery` | Landed. `UserQueryRunner` applies `UserVisibility`. `users_visibility` itself stays on the identity row. The queries checklist row stays IssueQuery. |
| 8 | Account administration under `users.admin` | Landed. Create, edit, lock, unlock, activate, delete, and group membership. Delete reassigns authorship to `AnonymousUser` and fails closed when that row is missing. |
| 9 | Mail-notification values and the `user_preferences.others` codec | Landed. `others` is a JSON object. Text that is not a JSON object is ignored on read. Delivery is the outbound mail row. |
| 10 | `session` and `autologin` token rows; operator-supplied administrator seed | Landed. Sign-in writes one `session` token. `autologin` is a cookie. `session_lifetime` and `session_timeout` are minutes, and `0` disables each cap. |

## Phase 1 behavior

`App\Domain\Auth\RedminePassword` seals and checks the digest. `App\Domain\Auth\CredentialChecker` resolves the identifier and returns a decision. `App\Auth\RedmineUserProvider` is the `redmine` user provider. It does not ask Laravel’s hasher to replace `hashed_password`.

HTTP, session middleware. `GET /login` renders the Inertia page `Auth/Login`. That page posts to the same session action as the Blade form. `GET /login?view=blade` still renders the Phase 1 Blade form. `GET /` is the Inertia health smoke page and is not a sign-in screen.

| Method and path | Behavior |
| --- | --- |
| `GET /login` | Inertia sign-in page `Auth/Login`. Fields are login and password. The form posts to `POST /login`. |
| `GET /login?view=blade` | Phase 1 Blade sign-in form. Same fields and the same post. |
| `POST /login` | Session sign-in. JSON returns the id and login. A form posts back to `/` on success. |
| `POST /logout` | Invalidates the session |
| `GET /custom-fields/attachments/{id}` | Authorized download of a custom-field attachment. Uses the session user when one is present. A guest is allowed only when the host is visible to Anonymous. Does not sign anyone in. |
| `GET /custom-fields/links/{id}` | Authorized resolution of a link custom value. Same actor rule. Does not request the remote URL. |

A wrong password, an unknown identifier, a group or anonymous row, a non-active status other than locked or registered, and a failed external auth check all return “Invalid user or password”. A password that succeeds while a second factor is still required does not open a session; the response asks for that factor. A correct local password on status `3` returns “Your account is locked.” A correct local password on status `2` returns “Your account was created and is now pending administrator approval.” When `self_registration` is `1`, that same sign-in returns “Your account has not yet been activated.” and the session stores `registered_user_id` so another `register` token can be issued. The domain decision distinguishes the gates for tests. Empty login or password is a validation error. A signed-in user whose row no longer passes the session gate is signed out on the next request.

`last_login_on` is set when the session is created. Phase 10 writes one `tokens` row with action `session` when that session opens. The Phase 2 comparison expects that single row.

## Phase 2 behavior

`ActionToken` issues one `recovery` or `register` row per account. The value is 40 lowercase hex characters. A row is expired when `created_on` is at least 86400 seconds old. A new issue for the same action deletes the previous row. `password_reset_tokens` stays unused.

`AccountRecovery` looks up the trimmed address through `email_addresses`, case-insensitive. Zero matches or more than one user store nothing. An active local account (`type` User, no `auth_source_id`) gets a `recovery` token. A status `2` account gets a `register` token only when self-registration is email activation. The HTTP notice is “An email with instructions to choose a new password has been sent to you.” either way. Lost password is off when `lost_password` is `0`; a missing row leaves it on.

A usable `recovery` token sets a new digest, clears `must_change_passwd`, stamps `passwd_changed_on`, and deletes the token. The account must still be active. While `must_change_passwd` is set, the new password must not match the current digest. The minimum length defaults to 8 (`password_min_length`). `password_required_char_classes` defaults to 0. Those rules apply when a password is chosen, not when an existing digest is checked.

`RegistrationService` rejects mode `0`. Mode `1` stores status `2` and a `register` token. Mode `2` stores status `2` and no token. Mode `3` stores status `1` and the session guard signs that account in. Activation of a usable `register` token moves status from `2` to `1` and deletes the token. It does not sign the account in. A new account stores `mail_notification` from `default_notification_option` when that value is one of the six legal ones, otherwise `only_my_events`, plus a `user_preferences` row with `hide_mail` true. `others` stays empty.

`must_change_passwd` does not block the password check. The session opens, then `RequirePasswordChange` sends every other request to `GET /my/password` until the flag is cleared. Sign-out stays available. A JSON request to a blocked path returns 403. An Inertia sign-in leaves the client with `X-Inertia-Location` for that form.

| Method and path | Behavior |
| --- | --- |
| `GET /account/register` | Registration form when self-registration is not `0`. Otherwise redirect `/`. |
| `POST /account/register` | Creates the account as the mode above. |
| `GET /account/activate?token=` | Consumes a usable `register` token. |
| `POST /account/activation_email` | Reissues a `register` token for the registered id stored at sign-in. |
| `GET /account/lost_password` | Request form. A `token` query is stored on the session and the response redirects to this path without the query. The next GET shows the reset form when that token is usable, and redirects `/` when it is missing or expired. |
| `POST /account/lost_password` | Starts recovery. The response does not include the token value. |
| `POST /account/lost_password/reset` | Sets the password from the session token. |
| `GET /my/password` | Password form for the signed-in account. |
| `POST /my/password` | Checks the current password, then stores a new digest. |

These forms are Blade. They are not Redmine screens. The Inertia sign-in page links to register and lost password when those settings allow it. The link is a full page load.

## Inventory (pointer, not a second schema)

| Concept | Redmine 7.0.1 store | Notes |
| --- | --- | --- |
| Account row | `users` | STI `User`, `Group`, `AnonymousUser`. Status codes are decision 4. |
| Mail | `email_addresses` | No `email` column on `users`. Sign-in may use any address (decision 2). |
| Action tokens | `tokens` | Actions are decision 3. `recovery` and `register` expire after one day. `api` and `feeds` do not. `session`, `autologin`, and `twofa_backup_code` keep at most ten rows. |
| Preferences | `user_preferences` | `others` is JSON, not Ruby YAML. |
| External auth | `auth_sources` | LDAP type `AuthSourceLdap`. The tested directory is in memory. |
| Group membership | `groups_users` | Project role expansion stays in [domain.md](domain.md). |
| Project membership | `members`, `member_roles` | `manage_members` is not account admin. |
| Who can see which users | `roles.users_visibility` | Decision 11. Applied by `UserVisibility`. The directory uses the same scope. |
| OAuth | `oauth_applications`, `oauth_access_grants`, `oauth_access_tokens` | Authorization code, PKCE, and refresh tokens. No OpenID Connect. |
| Watchers | `watchers.user_id` | A user id, not a credential. |

Laravel `sessions` is the Phase 1 web session store. `sessions.user_id` references `users.id`. `password_reset_tokens` is unused by the product.

## Intentional differences

- The web session row is Laravel `sessions`. A `tokens.action = session` row can revoke that session. Autologin is a cookie holding an `autologin` token, not a remember column.
- A user with `auth_source_id` is checked against that LDAP source. The local digest is not the credential. A blank host fails closed before the directory is contacted. On-the-fly creation stores a random sealed digest so the directory password is not kept.
- `user_preferences.others` is JSON. A Ruby YAML document in that column is ignored on read. `gantt_zoom` (`1` through `4`) and `gantt_months` (a positive integer) are stored as strings when a signed-in user changes the gantt window. Those two keys are compared on the calendar and Gantt row. The preferences comparison itself stays `expectations/users-auth/gap.json`.
- `settings.notified_events` is JSON. Message ids have no random suffix. A blank `mail_notification` does not receive mail. Lock and unlock queue an informational message. The HTTP notice still does not include the token value; the mail body does. News, document, and file mail, and message and wiki mail, are compared on their notification rows.
- `users_visibility` is applied by `UserVisibility` on the ACL path and on the user directory. The Phase 2 sign-in comparison does not read it.
- OpenID Connect is not in the 7.0.1 schema pin. A live LDAP directory is not implemented. The REST API is compared by `tests/Parity/RestApiParityTest.php`. Activity Atom feeds cover issues, journals, time entries, news, documents, files, wiki edits, and messages. Changesets have no activity provider.
- The forty-zero placeholder never verifies, even if a digest collided with it.
- Posted passwords are not trimmed. Identifiers are trimmed.
- A wrong password stays on the generic notice. Locked and registered accounts get their own notice only after the digest matches.
- The sign-in screen is an Inertia page. Register, lost password, activation, and the password form are Blade. None of them is a Redmine screen.

## What the parity row covers

`tests/Parity/UsersAuthParityTest.php` loads the shared pin and compares digest check, session login and logout, status notices, `must_change_passwd`, and the `recovery` / `register` token rules to `tests/Parity/fixtures/redmine-7.0.1/expectations/users-auth/sign-in.json`. `tests/Parity/UsersAuthGapParityTest.php` compares the later phases to `tests/Parity/fixtures/redmine-7.0.1/expectations/users-auth/gap.json`. Passing the ACL/workflow smoke does not close users and auth. `users_visibility` is part of the identity comparison. OpenID Connect and live LDAP stay out of both comparisons. The REST API is a separate checklist row. Outbound mail and activity are separate checklist rows.

## Checklist links

| Checklist | Users / auth status |
| --- | --- |
| [parity-checklist.md](parity-checklist.md) | **VERIFIED** for the Phase 2 row, the later-phase sub-rows that cite a passing pin comparison, the outbound mail row, and the activity row. OpenID Connect is **N/A**. The full REST API row is **VERIFIED** by `tests/Parity/RestApiParityTest.php`. Live LDAP stays **NOT VERIFIED**. `users_visibility` is on the identity row. Not a 0.1 tag. |
| [acl-workflow-parity-gate.md](acl-workflow-parity-gate.md) | Smoke **PASS** is not the checklist. `users_visibility` is applied by `UserVisibility`. The directory comparison is the users and authentication row. Not a 0.1 tag. |
| [QUALITY.md](../QUALITY.md) | No 0.1 tag. Phase 1 is not a production-ready claim. |
