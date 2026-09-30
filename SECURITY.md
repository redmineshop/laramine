# Security policy

## Supported versions

Laramine is early open-source work. There is no tagged release, no long-term support branch, and no version recommended for production.

| Line | Security updates |
| --- | --- |
| `main` | Best-effort during early development |
| Tagged releases | None published |

Fixes land on `main`. There is no maintained stable series to backport to.

## Reporting a vulnerability

Report suspected vulnerabilities in this repository privately. Do not open a public GitHub issue for the details, and do not post exploit steps in a pull request.

Use GitHub private vulnerability reporting:

[https://github.com/redmineshop/laramine/security/advisories/new](https://github.com/redmineshop/laramine/security/advisories/new)

Include:

- the area you believe is affected (domain service, schema, auth, dependency use, CI)
- the impact you expect
- a reproduction, if you have one, against `main`
- the commit you tested

This policy does not publish a security email or a bug bounty. A maintainer will acknowledge when they can. Early-stage response time is not guaranteed. Please wait for a reply before disclosing publicly.

If private reporting is turned off for the repository, contact a maintainer through GitHub and wait for a private channel. Keep the report off the public issue tracker until a fix or an explicit decline is published.

## Scope

In scope: the PHP application, migrations, and workflow files in this repository.

Out of scope: Redmine itself (a separate GPLv2 project), and vulnerabilities that exist only in an unverified claim of Redmine compatibility. Dependency advisories belong to the upstream package unless this repository’s own use of that package is the defect.
