# Claude Code

Follow [AGENTS.md](AGENTS.md). That file points at [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), [QUALITY.md](QUALITY.md), and the rest of the agent kit.

Cursor rules: `.cursor/rules/laramine.mdc`.

When the task continues issue workflow, saved queries, or custom fields, follow `.cursor/skills/continue-domain-work/SKILL.md`.

The P0 table and column layout, users and authentication, identity, projects nested-set, workflows, custom fields, queries, journals, time entries and attachments, outbound mail, activity, news, documents, and files, wiki, boards and forums, calendar and Gantt, Textile and Markdown rendering, and the notification and activity rows for those modules in `docs/parity-checklist.md` are **VERIFIED** by the tests named there. Wiki HTTP is **VERIFIED** by `tests/Parity/WikiHttpParityTest.php`. Boards and forums HTTP is **VERIFIED** by `tests/Parity/BoardsHttpParityTest.php`. The full REST API row is **VERIFIED** by `tests/Parity/RestApiParityTest.php`. The live LDAP row is **VERIFIED** by `tests/Parity/LiveLdapParityTest.php`. The reactions row is **VERIFIED** by `tests/Parity/ReactionParityTest.php`. OpenID Connect is **N/A**. Activity for changesets stays **NOT VERIFIED**. Wiki and boards visual UX stays **NOT VERIFIED**. Every other row is **NOT VERIFIED**. Do not describe this repository as production-ready, and do not copy Redmine GPLv2 source into the tree.
