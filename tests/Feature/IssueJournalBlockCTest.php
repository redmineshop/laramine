<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\IssueShowView;
use App\Domain\Issues\History\JournalActionList;
use App\Domain\Issues\History\JournalEntryView;
use App\Domain\Issues\History\JournalMenuItemView;
use App\Domain\Issues\IssueService;
use App\Models\Journal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Journals Block C: tab filters, the more menu, anchor hrefs, and note controls.
 *
 * This is Laramine behavior. It does not compare rows with a Redmine 7.0.1
 * database. Parity stays NOT VERIFIED.
 */
class IssueJournalBlockCTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_c_tabs_keep_notes_or_detail_journals(): void
    {
        $world = DomainFixture::boot('block-c-tabs');
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-tabs']);
        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);

        $mixedIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Mixed history',
            'status_id' => $world->newStatus->id,
            'done_ratio' => 0,
        ]);
        $issues->update($admin, $mixedIssue, ['notes' => 'Only a note.']);
        $issues->update($admin, $mixedIssue->fresh(), ['done_ratio' => 10]);
        $issues->update($admin, $mixedIssue->fresh(), [
            'status_id' => $world->inProgress->id,
            'notes' => 'Mixed note.',
        ]);

        $show = $history->present($admin, $mixedIssue->fresh());
        $this->assertSame(
            [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_NOTES, IssueHistoryPresenter::TAB_PROPERTIES],
            $show->historyTabLabels,
            'criterion 16',
        );
        $this->assertCount(3, $show->historyEntries);
        $noteOnly = $show->historyEntries[0];
        $detailOnly = $show->historyEntries[1];
        $mixed = $show->historyEntries[2];
        $this->assertTrue($noteOnly->hasNote);
        $this->assertFalse($noteOnly->hasDetails);
        $this->assertFalse($detailOnly->hasNote);
        $this->assertTrue($detailOnly->hasDetails);
        $this->assertTrue($mixed->hasNote);
        $this->assertTrue($mixed->hasDetails);

        $this->assertSame([$noteOnly->journalId, $mixed->journalId], $this->ids($show->notesEntries), 'criterion 16');
        $this->assertSame([$detailOnly->journalId, $mixed->journalId], $this->ids($show->propertyChangeEntries), 'criterion 16');

        $mixedOnNotes = $this->byId($show->notesEntries, $mixed->journalId);
        $this->assertSame('Mixed note.', $mixedOnNotes->noteText);
        $this->assertNotSame([], $mixedOnNotes->propertyChanges, 'criterion 16 notes tab keeps property lines');

        $mixedOnProperties = $this->byId($show->propertyChangeEntries, $mixed->journalId);
        $this->assertTrue($mixedOnProperties->hasNote, 'criterion 16 the journal is kept');
        $this->assertNull($mixedOnProperties->noteText, 'criterion 16 the note body is omitted');
        $this->assertNull($mixedOnProperties->noteHtml);
        $this->assertSame(['reaction'], $this->keys($mixedOnProperties), 'criterion 16');
        $this->assertSame('thumbs-up', $mixedOnProperties->actions[0]->label);
        $this->assertNotSame([], $mixedOnProperties->propertyChanges);

        $notesIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Notes only',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $notesIssue, ['notes' => 'Just a note.']);
        $notesShow = $history->present($admin, $notesIssue->fresh());
        $this->assertSame(
            [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_NOTES],
            $notesShow->historyTabLabels,
            'criterion 16',
        );
        $this->assertSame([], $notesShow->propertyChangeEntries);

        $detailIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Details only',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $detailIssue, ['subject' => 'Details only renamed']);
        $detailShow = $history->present($admin, $detailIssue->fresh());
        $this->assertSame(
            [IssueHistoryPresenter::TAB_HISTORY, IssueHistoryPresenter::TAB_PROPERTIES],
            $detailShow->historyTabLabels,
            'criterion 16',
        );
        $this->assertSame([], $detailShow->notesEntries);

        $emptyIssue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'No note and no detail',
            'status_id' => $world->newStatus->id,
        ]);
        $this->storeJournal($emptyIssue->id, $admin->id, null);
        $this->storeJournal($emptyIssue->id, $admin->id, '   ');
        $emptyShow = $history->present($admin, $emptyIssue->fresh());
        $this->assertSame([IssueHistoryPresenter::TAB_HISTORY], $emptyShow->historyTabLabels, 'criterion 16');
        $this->assertCount(2, $emptyShow->historyEntries);
        $this->assertSame([], $emptyShow->notesEntries);
        $this->assertSame([], $emptyShow->propertyChangeEntries);
        $this->assertFalse($emptyShow->historyEntries[1]->hasNote);
    }

    public function test_block_c_anchor_href_follows_visible_order(): void
    {
        $world = DomainFixture::boot('block-c-anchors');
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-anchors']);
        $privateAuthor = $this->member($world, 'anchor_private', [
            'view_issues',
            'add_issue_notes',
            'set_notes_private',
        ]);
        $public = $this->member($world, 'anchor_public', [
            'view_issues',
            'add_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Anchors',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $issue, ['notes' => 'Visible note.']);
        $issues->update($privateAuthor, $issue->fresh(), [
            'notes' => 'Hidden note.',
            'private_notes' => true,
        ]);
        $issues->update($admin, $issue->fresh(), ['subject' => 'Anchors renamed']);

        $adminShow = $history->present($admin, $issue->fresh());
        $publicShow = $history->present($public, $issue->fresh());

        $this->assertSame(['#1', '#2', '#3'], [
            $adminShow->historyEntries[0]->anchorLabel,
            $adminShow->historyEntries[1]->anchorLabel,
            $adminShow->historyEntries[2]->anchorLabel,
        ], 'criterion 18');
        $this->assertSame(['#note-1', '#note-2', '#note-3'], [
            $adminShow->historyEntries[0]->anchorHref,
            $adminShow->historyEntries[1]->anchorHref,
            $adminShow->historyEntries[2]->anchorHref,
        ], 'criterion 18');
        foreach ($adminShow->historyEntries as $entry) {
            $this->assertNotSame('#'.$entry->journalId, $entry->anchorHref, 'criterion 18');
            $this->assertNotSame('#note-'.$entry->journalId, $entry->anchorHref, 'criterion 18');
        }

        $this->assertSame(['#1', '#2'], [
            $publicShow->historyEntries[0]->anchorLabel,
            $publicShow->historyEntries[1]->anchorLabel,
        ], 'criterion 18');
        $this->assertSame(['#note-1', '#note-2'], [
            $publicShow->historyEntries[0]->anchorHref,
            $publicShow->historyEntries[1]->anchorHref,
        ], 'criterion 18');
        $this->assertSame('Visible note.', $publicShow->historyEntries[0]->noteText);
        $this->assertFalse($publicShow->historyEntries[1]->hasNote);
        $this->assertStringNotContainsString('Hidden note.', $this->blob($publicShow));
        $this->assertSame(
            [$publicShow->historyEntries[0]->journalId],
            $this->ids($publicShow->notesEntries),
        );
    }

    public function test_block_c_quote_edit_and_menu_follow_note_permissions(): void
    {
        $world = DomainFixture::boot('block-c-perms');
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-perms']);
        $quoter = $this->member($world, 'quoter', [
            'view_issues',
            'add_issue_notes',
        ]);
        $ownEditor = $this->member($world, 'own_editor', [
            'view_issues',
            'add_issue_notes',
            'edit_own_issue_notes',
        ]);
        $editor = $this->member($world, 'any_editor', [
            'view_issues',
            'edit_issue_notes',
        ]);
        $viewer = $this->member($world, 'viewer', [
            'view_issues',
        ]);
        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Permissions',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $issue, ['notes' => 'Admin note.']);
        $issues->update($ownEditor, $issue->fresh(), ['notes' => 'Own note.']);
        $issues->update($admin, $issue->fresh(), ['subject' => 'Permissions renamed']);

        $adminShow = $history->present($admin, $issue->fresh());
        $adminNote = $this->note($adminShow, 'Admin note.');
        $this->assertSame(['reaction', 'quote', 'edit', 'more'], $this->keys($adminNote), 'criterion 19');
        $this->assertSame(
            [JournalActionList::COPY_LINK, JournalActionList::DELETE],
            $this->menuLabels($adminNote),
            'criterion 17',
        );
        $copyLink = $this->menu($adminNote)[0];
        $this->assertSame('copy_link', $copyLink->key, 'criterion 17');
        $this->assertSame('#note-1', $adminNote->anchorHref, 'criterion 17');
        $this->assertSame(
            'http://localhost:3000/issues/'.$issue->id.$adminNote->anchorHref,
            $copyLink->fragment,
            'criterion 17',
        );

        $detail = $adminShow->historyEntries[2];
        $this->assertFalse($detail->hasNote);
        $this->assertSame(['reaction', 'more'], $this->keys($detail), 'criterion 17');
        $this->assertSame([JournalActionList::COPY_LINK], $this->menuLabels($detail), 'criterion 17');

        $quoterShow = $history->present($quoter, $issue->fresh());
        $quoted = $this->note($quoterShow, 'Admin note.');
        $this->assertSame(['reaction', 'quote', 'more'], $this->keys($quoted), 'criterion 19');
        $this->assertSame([JournalActionList::COPY_LINK], $this->menuLabels($quoted), 'criterion 17');

        $ownShow = $history->present($ownEditor, $issue->fresh());
        $ownNote = $this->note($ownShow, 'Own note.');
        $othersNote = $this->note($ownShow, 'Admin note.');
        $this->assertSame(['reaction', 'quote', 'edit', 'more'], $this->keys($ownNote), 'criterion 19');
        $this->assertSame(
            [JournalActionList::COPY_LINK, JournalActionList::DELETE],
            $this->menuLabels($ownNote),
            'criterion 19',
        );
        $this->assertSame(['reaction', 'quote', 'more'], $this->keys($othersNote), 'criterion 19');
        $this->assertSame([JournalActionList::COPY_LINK], $this->menuLabels($othersNote), 'criterion 19');

        $editorShow = $history->present($editor, $issue->fresh());
        $editable = $this->note($editorShow, 'Admin note.');
        $this->assertSame(['reaction', 'edit', 'more'], $this->keys($editable), 'criterion 19');
        $this->assertNotContains('quote', $this->keys($editable), 'criterion 19');
        $this->assertSame(
            [JournalActionList::COPY_LINK, JournalActionList::DELETE],
            $this->menuLabels($editable),
            'criterion 19',
        );
        $editorDetail = $editorShow->historyEntries[2];
        $this->assertSame(['reaction', 'more'], $this->keys($editorDetail), 'criterion 19');
        $this->assertSame([JournalActionList::COPY_LINK], $this->menuLabels($editorDetail));

        $viewerShow = $history->present($viewer, $issue->fresh());
        $viewed = $this->note($viewerShow, 'Own note.');
        $this->assertSame(['reaction', 'more'], $this->keys($viewed), 'criterion 19');
        $this->assertSame([JournalActionList::COPY_LINK], $this->menuLabels($viewed), 'criterion 19');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function member(DomainFixture $world, string $name, array $permissions): User
    {
        $user = User::factory()->create(['login' => $name]);
        $role = Role::query()->create([
            'name' => $name,
            'builtin' => 0,
            'assignable' => true,
            'permissions' => $permissions,
            'issues_visibility' => 'default',
        ]);
        app(MembershipService::class)->assignRole($world->project, $user, $role);

        return $user;
    }

    private function storeJournal(int $issueId, int $userId, ?string $notes): void
    {
        Journal::query()->create([
            'journalized_id' => $issueId,
            'journalized_type' => 'Issue',
            'user_id' => $userId,
            'notes' => $notes,
            'private_notes' => false,
            'created_on' => now(),
        ]);
    }

    /**
     * @param  list<JournalEntryView>  $entries
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->journalId;
        }

        return $ids;
    }

    /**
     * @param  list<JournalEntryView>  $entries
     */
    private function byId(array $entries, int $journalId): JournalEntryView
    {
        foreach ($entries as $entry) {
            if ($entry->journalId === $journalId) {
                return $entry;
            }
        }

        $this->fail('Missing journal '.$journalId);
    }

    private function note(IssueShowView $show, string $text): JournalEntryView
    {
        foreach ($show->historyEntries as $entry) {
            if ($entry->noteText === $text) {
                return $entry;
            }
        }

        $this->fail('Missing note: '.$text);
    }

    /**
     * @return list<string>
     */
    private function keys(JournalEntryView $entry): array
    {
        $keys = [];
        foreach ($entry->actions as $action) {
            $keys[] = $action->key;
        }

        return $keys;
    }

    /**
     * @return list<JournalMenuItemView>
     */
    private function menu(JournalEntryView $entry): array
    {
        foreach ($entry->actions as $action) {
            if ($action->key === 'more') {
                return $action->menuItems;
            }
        }

        $this->fail('Missing more control');
    }

    /**
     * @return list<string>
     */
    private function menuLabels(JournalEntryView $entry): array
    {
        $labels = [];
        foreach ($this->menu($entry) as $item) {
            $labels[] = $item->label;
        }

        return $labels;
    }

    private function blob(IssueShowView $show): string
    {
        $chunks = [];
        foreach ([$show->historyEntries, $show->notesEntries, $show->propertyChangeEntries] as $entries) {
            foreach ($entries as $entry) {
                $chunks[] = (string) $entry->noteText;
                $chunks[] = (string) $entry->noteHtml;
            }
        }

        return implode("\n", $chunks);
    }
}
