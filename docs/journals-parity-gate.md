# Journals security and parity gate

This is the Laramine checklist for issue history and private notes. It follows the S1 acceptance shape: visible behavior for history, property diffs, textile emphasis, journal controls, the success flash, and private-note permissions.

**Parity is NOT VERIFIED.** A green smoke in this repo is Laramine behavior. It is not a comparison against a Redmine 7.0.1 database. Do not describe the project as production-ready from this file.

Block C criteria 16–19 are covered by Laramine tests. That is not a Redmine comparison, and it does not close the deferred rows at the bottom of this file.

## Where the behavior lives

| Piece | Code |
| --- | --- |
| Journal write on issue update | `App\Domain\Issues\IssueJournalWriter`, called from `IssueService::update` |
| Relation-add journal | `App\Domain\Issues\IssueRelationService` |
| Quote, edit, and delete of a note | `App\Domain\Issues\JournalNoteService` with `JournalNoteAccess` |
| History, notes rendering, checkbox, flash | `App\Domain\Issues\History\IssueHistoryPresenter` |
| Private-note query rule | `App\Domain\Queries\JournalVisibility` |

Domain behavior is described in [domain.md](domain.md). The parity row stays **NOT VERIFIED** in [parity-checklist.md](parity-checklist.md).

## Smoke

`tests/Feature/IssueJournalSmokeTest.php` builds the fixture shape: roles `admin`, `notes_private`, and `notes_public`; issue journals in display order for a mixed update, a relation-add, and a private note; plus an issue with zero journals.

`tests/Unit/JournalPresentationTest.php` covers emphasis, italic old/new values, the relation-add sentence, header controls, and the more-menu labels.

`tests/Feature/IssueJournalBlockCTest.php` covers which journals the Notes and Property changes tabs keep, the `#note-n` href, the more-menu labels, and which actors see quote, edit, and Delete.

`tests/Feature/IssueJournalNoteWriteTest.php` covers quote, edit, and delete rows on MySQL. `tests/Unit/JournalQuoteTextTest.php` covers the quoted note text. Those tests do not compare rows with a Redmine 7.0.1 database.

The relation line is `Related to {tracker} #{id}: {subject} added`, using the other issue's id. The 7.0.1 capture showed `#2` because that issue was id 2. The smoke asserts the same sentence with the id this fixture actually stored.

MySQL 8 is the database (`phpunit.xml`). These tests do not live under `tests/Parity`.

## Block A — smoke covers these

| # | Check | Automated by |
| --- | --- | --- |
| 1 | History block absent when the issue has zero journals | `test_block_a_and_block_b_history_smoke` |
| 2 | Tab labels History, Notes, and Property changes on the mixed admin show | same |
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

## Block C — smoke covers these

| # | Check | Automated by |
| --- | --- | --- |
| 16 | Notes keeps journals with note text, including mixed journals, and still shows their property lines. Property changes keeps journals that have `journal_details`, including mixed journals, omits the note body, and leaves only reaction. A journal with neither is History only. Notes is omitted when no visible journal has a note. Property changes is omitted when none has a detail. | `test_block_c_tabs_keep_notes_or_detail_journals` |
| 17 | The ⋯ menu lists Copy link on every journal. Delete is listed only when that note may be edited. Copy link uses the `#note-n` fragment. | `test_block_c_quote_edit_and_menu_follow_note_permissions` and the unit test |
| 18 | The anchor label stays `#n` in visible order. The href is `#note-n` for that index, including after a private journal is omitted. | `test_block_c_anchor_href_follows_visible_order` |
| 19 | Quote requires `add_issue_notes`. Edit and Delete require `edit_issue_notes`, or `edit_own_issue_notes` when `journals.user_id` is the actor. A detail-only journal does not show quote, edit, or Delete. | `test_block_c_quote_edit_and_menu_follow_note_permissions` |

These checks decide which controls are visible. They do not write a journal row. The writes are the next section.

## Journal note writes

`JournalNoteService` quotes, edits, and deletes a note under the same permission rules as the markers. A green test here is Laramine behavior. Parity stays **NOT VERIFIED**.

| Action | What is stored |
| --- | --- |
| Quote | A new issue journal whose note quotes the source text, when the actor has `add_issue_notes` and can see that journal. A private source stays private on the new row. |
| Edit | `journals.notes`, `updated_by_id`, and `updated_on` when the actor may edit that note. `user_id`, `created_on`, and `journal_details` stay. A blank note is rejected. |
| Delete | The journal row is removed when it has no details. When details exist, `notes` is cleared and the detail rows stay. Same permission rule as edit. |

`tests/Feature/IssueJournalNoteWriteTest.php` is the MySQL coverage, including a deny path for each action.

## Still open

| Item | Status |
| --- | --- |
| Journal note writes compared with Redmine 7.0.1 | **Open.** Laramine stores the rows. No parity comparison has been recorded. |
| Download all files | **Open.** The menu does not list it. Journal attachments are not in this slice. |
| Thumbnail-only journals on Notes | **Open.** A detail-only journal is not kept on Notes for file thumbnails. |
| Absolute copy-link URL | **Open.** Copy link carries `#note-n`. There is no issue URL. |
| Time entries and changeset tabs | **Open.** Outside this slice. |
| Redmine parity VERIFIED, tag 0.1 | **Open.** Not claimed. |

An open item does not authorize a parity-verified or production-ready claim.
