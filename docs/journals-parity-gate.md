# Journals security and parity gate

This is the Laramine checklist for issue history and private notes. It follows the S1 acceptance shape: visible behavior for history, property diffs, textile emphasis, journal controls, the success flash, and private-note permissions.

**Parity is NOT VERIFIED.** A green smoke in this repo is Laramine behavior. It is not a comparison against a Redmine 7.0.1 database. Do not describe the project as production-ready from this file.

Block C stays open. Nothing in this file marks criteria 16–19 as passed.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Journal write on issue update | `App\Domain\Issues\IssueJournalWriter`, called from `IssueService::update` |
| Relation-add journal | `App\Domain\Issues\IssueRelationService` |
| History, notes rendering, checkbox, flash | `App\Domain\Issues\History\IssueHistoryPresenter` |
| Private-note query rule | `App\Domain\Queries\JournalVisibility` |

Domain behavior is described in [domain.md](domain.md). The parity row stays **NOT VERIFIED** in [parity-checklist.md](parity-checklist.md).

## Smoke

`tests/Feature/IssueJournalSmokeTest.php` builds the fixture shape: roles `admin`, `notes_private`, and `notes_public`; issue journals in display order for a mixed update, a relation-add, and a private note; plus an issue with zero journals.

`tests/Unit/JournalPresentationTest.php` covers emphasis, italic old/new values, the relation-add sentence, and which header controls are present.

The relation line is `Related to {tracker} #{id}: {subject} added`, using the other issue's id. The 7.0.1 capture showed `#2` because that issue was id 2. The smoke asserts the same sentence with the id this fixture actually stored.

MySQL 8 is the database (`phpunit.xml`). These tests do not live under `tests/Parity`.

## Block A — smoke covers these

| # | Check | Automated by |
| --- | --- | --- |
| 1 | History block absent when the issue has zero journals | `test_block_a_and_block_b_history_smoke` |
| 2 | Tab labels History, Notes, and Property changes when a journal exists | same |
| 3 | History shows a mixed journal (property lines and the note) | same |
| 4 | History shows the property-only relation-add journal | same |
| 5 | `Status changed from New to In Progress` and `% Done changed from 0 to 30` | same |
| 6 | Old and new values in those lines are italic (`em`) | same, and the unit test |
| 7 | Textile `*emphasis*` renders as italics | same, and the unit test |
| 8 | Note journal header: reaction (thumbs-up), quote, edit (pencil), ⋯, and `#n` | same |
| 9 | Property-only header: reaction and ⋯, without quote or edit | same |
| 10 | Anchor labels `#1`, `#2`, `#3` in display order, not `journals.id` | same |
| 11 | Flash text `✓ Successful update.` with tone `green` after a successful update | same |
| 12 | Private notes checkbox present and unchecked for the admin show | same |

Quote, edit, and ⋯ are presence markers. The smoke does not click them.

## Block B — smoke covers these

| # | Check | Automated by |
| --- | --- | --- |
| 13 | `notes_private` (`view_private_notes`) sees the private note text | `test_block_a_and_block_b_history_smoke` |
| 14 | `notes_public` does not see that note text | same |
| 15 | Private notes checkbox follows `set_notes_private` | same |

The presenter omits a whole private journal when the actor lacks `view_private_notes`, including the author (`test_private_journal_is_hidden_from_its_author_without_view_private_notes`). A private flag on a status change hides that property line too (`test_private_flag_hides_property_changes_without_view_private_notes`). `test_note_private_and_relation_permissions_deny` rejects a note without `add_issue_notes`, a private flag without `set_notes_private`, and a relation without `manage_issue_relations`.

## Block C — open, not passed

| # | Item | Status |
| --- | --- | --- |
| 16 | Which journals the Notes and Property changes tabs keep | **Open.** Labels are asserted. No filter rule is implemented. |
| 17 | Journal ⋯ menu items | **Open.** The control is present. Menu labels are not invented. |
| 18 | Anchor fragment / href | **Open.** The label is `#n`. No href is assigned. |
| 19 | Quote or edit hidden by `edit_issue_notes` / `edit_own_issue_notes` | **Open.** Those permissions are not applied to the controls. |

An open Block C item does not authorize a parity-verified or production-ready claim.
