<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\DomainException;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\IssueShowView;
use App\Domain\Issues\History\JournalEntryView;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\IssueService;
use App\Domain\Issues\JournalNoteService;
use App\Domain\PermissionDeniedException;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Reaction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * Journal note quote, edit, and delete writes.
 *
 * This is Laramine behavior on MySQL. It does not compare rows with a
 * Redmine 7.0.1 database. Parity stays NOT VERIFIED.
 */
class IssueJournalNoteWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_persists_a_new_note_and_keeps_a_private_source_private(): void
    {
        $world = DomainFixture::boot('journal-quote');
        $admin = User::factory()->create(['admin' => true, 'login' => 'quote-admin']);
        $author = User::factory()->create([
            'login' => 'ada',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
        ]);
        $this->grant($world, $author, 'author', [
            'view_issues',
            'add_issue_notes',
            'set_notes_private',
        ]);
        $quoter = $this->grant($world, null, 'quoter', [
            'view_issues',
            'add_issue_notes',
            'view_private_notes',
        ]);
        $publicReader = $this->grant($world, null, 'public_reader', [
            'view_issues',
            'add_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $notes = app(JournalNoteService::class);
        $history = app(IssueHistoryPresenter::class);

        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Quote me',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($author, $issue, ['notes' => "Check the gate.\nThen ship."]);
        $issues->update($author, $issue->fresh(), [
            'notes' => 'Private source.',
            'private_notes' => true,
        ]);
        $source = $this->journalWithNote($issue, 'Check the gate.');
        $privateSource = $this->journalWithNote($issue, 'Private source.');
        $this->freezeUpdatedOn($issue);

        $quoted = $notes->quote($quoter, $source);
        $stored = Journal::query()->find($quoted->id);
        $this->assertInstanceOf(Journal::class, $stored);
        $this->assertSame($quoter->id, (int) $stored->user_id);
        $this->assertNull($stored->updated_by_id);
        $this->assertNull($stored->updated_on);
        $this->assertFalse((bool) $stored->private_notes);
        $this->assertSame(
            "Ada Lovelace wrote:\n> Check the gate.\n> Then ship.",
            $stored->notes,
        );
        $this->assertSame("Check the gate.\nThen ship.", Journal::query()->find($source->id)?->notes);
        $this->assertNull(Journal::query()->find($source->id)?->updated_on);
        $this->assertGreaterThan(
            '2020-01-02 00:00:00',
            $this->stamp($issue),
        );

        $show = $history->present($quoter, $issue->fresh());
        $this->assertNotNull($this->entryWith($show->notesEntries, $stored->notes));

        try {
            $notes->quote($publicReader, $privateSource);
            $this->fail('A private note stays hidden without view_private_notes.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('view_private_notes', $denied->permission);
        }

        $privateQuote = $notes->quote($quoter, $privateSource->fresh());
        $this->assertTrue((bool) $privateQuote->private_notes);
        $this->assertStringContainsString('Private source.', (string) $privateQuote->notes);
        $this->assertSame($quoter->id, (int) $privateQuote->user_id);
        $hidden = $history->present($publicReader, $issue->fresh());
        $this->assertStringNotContainsString('Private source.', $this->blob($hidden));

        $detailOnly = $this->detailJournal($issues, $admin, $world, 'Quote detail');
        try {
            $notes->quote($quoter, $detailOnly);
            $this->fail('A detail-only journal has no note to quote.');
        } catch (DomainException $exception) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $exception);
        }
    }

    public function test_edit_updates_the_note_and_records_the_editor(): void
    {
        $world = DomainFixture::boot('journal-edit');
        $admin = User::factory()->create([
            'admin' => true,
            'login' => 'edit-admin',
            'firstname' => 'Redmine',
            'lastname' => 'Admin',
        ]);
        $ownEditor = $this->grant($world, null, 'own_editor', [
            'view_issues',
            'add_issue_notes',
            'edit_own_issue_notes',
        ]);
        $editor = $this->grant($world, null, 'any_editor', [
            'view_issues',
            'edit_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $notes = app(JournalNoteService::class);
        $history = app(IssueHistoryPresenter::class);

        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Edit me',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($ownEditor, $issue, ['notes' => 'Own note.']);
        $issues->update($admin, $issue->fresh(), [
            'status_id' => $world->inProgress->id,
            'notes' => 'Mixed note.',
        ]);
        $own = $this->journalWithNote($issue, 'Own note.');
        $mixed = $this->journalWithNote($issue, 'Mixed note.');
        $detailId = JournalDetail::query()->where('journal_id', $mixed->id)->value('id');
        $createdOn = $mixed->created_on?->format('Y-m-d H:i:s');
        $this->freezeUpdatedOn($issue);

        $unchanged = $notes->edit($ownEditor, $own, '  Own note.  ');
        $this->assertNull($unchanged->updated_on);
        $this->assertNull($unchanged->updated_by_id);
        $this->assertSame('2020-01-01 00:00:00', $this->stamp($issue));

        $edited = $notes->edit($ownEditor, $own->fresh(), 'Own note, revised.');
        $this->assertSame('Own note, revised.', $edited->notes);
        $this->assertSame($ownEditor->id, (int) $edited->user_id);
        $this->assertSame($ownEditor->id, (int) $edited->updated_by_id);
        $this->assertNotNull($edited->updated_on);
        $this->assertGreaterThan('2020-01-02 00:00:00', $this->stamp($issue));

        $show = $history->present($ownEditor, $issue->fresh());
        $entry = $this->entryWith($show->historyEntries, 'Own note, revised.');
        $this->assertNotNull($entry);
        $this->assertSame(['reaction', 'quote', 'edit', 'more'], $this->keys($entry));

        try {
            $notes->edit($ownEditor, $mixed, 'Should not stick.');
            $this->fail('edit_own_issue_notes does not cover another author.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('edit_issue_notes', $denied->permission);
        }
        $this->assertSame('Mixed note.', Journal::query()->find($mixed->id)?->notes);

        try {
            $notes->edit($editor, $mixed, '   ');
            $this->fail('A blank note is not an edit.');
        } catch (DomainException $exception) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $exception);
        }

        $revised = $notes->edit($editor, $mixed->fresh(), 'Mixed note, revised.');
        $this->assertSame('Mixed note, revised.', $revised->notes);
        $this->assertSame($admin->id, (int) $revised->user_id);
        $this->assertSame($editor->id, (int) $revised->updated_by_id);
        $this->assertSame($createdOn, $revised->created_on?->format('Y-m-d H:i:s'));
        $this->assertSame($detailId, JournalDetail::query()->where('journal_id', $mixed->id)->value('id'));
        $editedEntry = $this->entryWith(
            $history->present($editor, $issue->fresh())->historyEntries,
            'Mixed note, revised.',
        );
        $this->assertInstanceOf(JournalEntryView::class, $editedEntry);
        $this->assertNotContains('quote', $this->keys($editedEntry));

        $byAdmin = $notes->edit($admin, $own->fresh(), 'Admin revision.');
        $this->assertSame('Admin revision.', $byAdmin->notes);
        $this->assertSame($admin->id, (int) $byAdmin->updated_by_id);
        $this->assertSame($ownEditor->id, (int) $byAdmin->user_id);
    }

    public function test_delete_removes_a_note_only_journal_and_clears_a_mixed_journal(): void
    {
        $world = DomainFixture::boot('journal-delete');
        $admin = User::factory()->create(['admin' => true, 'login' => 'delete-admin']);
        $owner = $this->grant($world, null, 'owner', [
            'view_issues',
            'add_issue_notes',
            'edit_own_issue_notes',
        ]);
        $editor = $this->grant($world, null, 'deleter', [
            'view_issues',
            'edit_issue_notes',
            'view_private_notes',
        ]);
        $issues = app(IssueService::class);
        $notes = app(JournalNoteService::class);
        $history = app(IssueHistoryPresenter::class);

        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Delete me',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($owner, $issue, ['notes' => 'Note only.']);
        $issues->update($admin, $issue->fresh(), [
            'done_ratio' => 10,
            'notes' => 'Keep the detail.',
            'private_notes' => true,
        ]);
        $noteOnly = $this->journalWithNote($issue, 'Note only.');
        $mixed = $this->journalWithNote($issue, 'Keep the detail.');
        $keptDetailId = JournalDetail::query()->where('journal_id', $mixed->id)->value('id');
        Reaction::query()->create([
            'reactable_type' => IssueJournalWriter::REACTABLE_JOURNAL,
            'reactable_id' => $noteOnly->id,
            'user_id' => $owner->id,
        ]);
        Reaction::query()->create([
            'reactable_type' => IssueJournalWriter::REACTABLE_JOURNAL,
            'reactable_id' => $mixed->id,
            'user_id' => $admin->id,
        ]);
        $this->freezeUpdatedOn($issue);

        $removed = $notes->delete($owner, $noteOnly);
        $this->assertNull($removed);
        $this->assertNull(Journal::query()->find($noteOnly->id));
        $this->assertSame(
            0,
            Reaction::query()->where('reactable_id', $noteOnly->id)->where('reactable_type', 'Journal')->count(),
        );
        $this->assertNotNull(Reaction::query()->where('reactable_id', $mixed->id)->first());
        $this->assertGreaterThan('2020-01-02 00:00:00', $this->stamp($issue));

        $afterNoteDelete = $history->present($admin, $issue->fresh());
        $this->assertNull($this->entryWith($afterNoteDelete->historyEntries, 'Note only.'));
        $this->assertCount(1, $afterNoteDelete->historyEntries);
        $this->assertSame('#note-1', $afterNoteDelete->historyEntries[0]->anchorHref);

        $cleared = $notes->delete($editor, $mixed->fresh());
        $this->assertInstanceOf(Journal::class, $cleared);
        $this->assertNull($cleared->notes);
        $this->assertTrue((bool) $cleared->private_notes);
        $this->assertSame($editor->id, (int) $cleared->updated_by_id);
        $this->assertNotNull($cleared->updated_on);
        $this->assertSame($keptDetailId, JournalDetail::query()->where('journal_id', $mixed->id)->value('id'));
        $this->assertNotNull(Journal::query()->find($mixed->id));

        $afterClear = $history->present($admin, $issue->fresh());
        $this->assertSame([], $afterClear->notesEntries);
        $this->assertCount(1, $afterClear->propertyChangeEntries);
        $this->assertNull($afterClear->propertyChangeEntries[0]->noteText);
        $this->assertSame(['reaction', 'more'], $this->keys($afterClear->historyEntries[0]));

        $detailOnly = $this->detailJournal($issues, $admin, $world, 'Delete detail');
        try {
            $notes->delete($editor, $detailOnly);
            $this->fail('A detail-only journal has no note to delete.');
        } catch (DomainException $exception) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $exception);
        }
        $this->assertNotNull(Journal::query()->find($detailOnly->id));
    }

    public function test_quote_edit_and_delete_deny_without_the_marker_permissions(): void
    {
        $world = DomainFixture::boot('journal-deny');
        $admin = User::factory()->create(['admin' => true, 'login' => 'deny-admin']);
        $author = $this->grant($world, null, 'note_author', [
            'view_issues',
            'add_issue_notes',
            'set_notes_private',
            'view_private_notes',
        ]);
        $viewer = $this->grant($world, null, 'viewer', [
            'view_issues',
        ]);
        $editor = $this->grant($world, null, 'editor_only', [
            'view_issues',
            'edit_issue_notes',
        ]);
        $blindEditor = $this->grant($world, null, 'blind_editor', [
            'view_issues',
            'edit_issue_notes',
            'add_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $notes = app(JournalNoteService::class);

        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Denied',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($author, $issue, [
            'notes' => 'Secret note.',
            'private_notes' => true,
        ]);
        $issues->update($author, $issue->fresh(), ['notes' => 'Public note.']);
        $secret = $this->journalWithNote($issue, 'Secret note.');
        $public = $this->journalWithNote($issue, 'Public note.');
        $foreign = Journal::query()->create([
            'journalized_id' => $issue->id,
            'journalized_type' => 'Message',
            'user_id' => $author->id,
            'notes' => 'Not an issue journal.',
            'private_notes' => false,
            'created_on' => now(),
        ]);

        try {
            $notes->quote($viewer, $public);
            $this->fail('add_issue_notes is required to quote a note.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('add_issue_notes', $denied->permission);
        }

        try {
            $notes->edit($viewer, $public, 'Nope.');
            $this->fail('edit_issue_notes is required to edit a note.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('edit_issue_notes', $denied->permission);
        }

        try {
            $notes->delete($viewer, $public);
            $this->fail('edit_issue_notes is required to delete a note.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('edit_issue_notes', $denied->permission);
        }
        $this->assertSame('Public note.', Journal::query()->find($public->id)?->notes);

        try {
            $notes->quote($editor, $public);
            $this->fail('edit_issue_notes does not grant quote.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('add_issue_notes', $denied->permission);
        }

        try {
            $notes->edit($blindEditor, $secret, 'Leaked.');
            $this->fail('A private note is not editable without view_private_notes.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('view_private_notes', $denied->permission);
        }
        $this->assertSame('Secret note.', Journal::query()->find($secret->id)?->notes);

        try {
            $notes->quote($editor, $foreign);
            $this->fail('Only an issue journal can be quoted.');
        } catch (DomainException $exception) {
            $this->assertNotInstanceOf(PermissionDeniedException::class, $exception);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(DomainFixture $world, ?User $user, string $name, array $permissions): User
    {
        $member = $user ?? User::factory()->create(['login' => $name]);
        $role = Role::query()->create([
            'name' => $name,
            'builtin' => 0,
            'assignable' => true,
            'permissions' => $permissions,
            'issues_visibility' => 'default',
        ]);
        app(MembershipService::class)->assignRole($world->project, $member, $role);

        return $member;
    }

    private function journalWithNote(Issue $issue, string $fragment): Journal
    {
        $journal = Journal::query()
            ->where('journalized_id', $issue->id)
            ->where('notes', 'like', '%'.$fragment.'%')
            ->orderBy('id')
            ->first();
        $this->assertInstanceOf(Journal::class, $journal);

        return $journal;
    }

    private function detailJournal(IssueService $issues, User $admin, DomainFixture $world, string $subject): Journal
    {
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => $subject,
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $issue, ['subject' => $subject.' renamed']);
        $journal = Journal::query()->where('journalized_id', $issue->id)->first();
        $this->assertInstanceOf(Journal::class, $journal);
        $this->assertNull($journal->notes);

        return $journal;
    }

    private function freezeUpdatedOn(Issue $issue): void
    {
        $issue->timestamps = false;
        $issue->forceFill(['updated_on' => '2020-01-01 00:00:00']);
        $issue->save();
        $issue->timestamps = true;
    }

    private function stamp(Issue $issue): string
    {
        $updated = $issue->fresh()?->updated_on;
        $this->assertNotNull($updated);

        return $updated->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<JournalEntryView>  $entries
     */
    private function entryWith(array $entries, ?string $text): ?JournalEntryView
    {
        foreach ($entries as $entry) {
            if ($entry->noteText === $text) {
                return $entry;
            }
        }

        return null;
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

    private function blob(IssueShowView $show): string
    {
        $chunks = [];
        foreach ([$show->historyEntries, $show->notesEntries, $show->propertyChangeEntries] as $entries) {
            foreach ($entries as $entry) {
                $chunks[] = (string) $entry->noteText;
            }
        }

        return implode("\n", $chunks);
    }
}
