<?php

namespace Tests\Feature;

use App\Domain\Acl\MembershipService;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\IssueShowView;
use App\Domain\Issues\History\JournalEntryView;
use App\Domain\Issues\History\JournalPropertyLine;
use App\Domain\Issues\IssueRelationService;
use App\Domain\Issues\IssueService;
use App\Domain\PermissionDeniedException;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DomainFixture;
use Tests\TestCase;

/**
 * S1 journals smoke for Block A (1–12) and Block B (13–15).
 *
 * Block C (16–19) is covered by IssueJournalBlockCTest. This file does not
 * compare the rows to a Redmine 7.0.1 database. Parity stays NOT VERIFIED.
 */
class IssueJournalSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_a_and_block_b_history_smoke(): void
    {
        $world = DomainFixture::boot();
        $admin = User::factory()->create([
            'login' => 'admin',
            'firstname' => 'Redmine',
            'lastname' => 'Admin',
            'admin' => true,
        ]);
        $notesPrivate = $this->member($world, 'notes_private', [
            'view_issues',
            'add_issue_notes',
            'set_notes_private',
            'view_private_notes',
        ]);
        $notesPublic = $this->member($world, 'notes_public', [
            'view_issues',
            'add_issue_notes',
        ]);

        $issues = app(IssueService::class);
        $history = app(IssueHistoryPresenter::class);
        $host = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Sample issue',
            'status_id' => $world->newStatus->id,
            'done_ratio' => 0,
        ]);
        $related = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Sample issue 2',
            'status_id' => $world->newStatus->id,
        ]);
        $untouched = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'No history yet',
            'status_id' => $world->newStatus->id,
        ]);

        $before = $history->present($admin, $host);
        $empty = $history->present($admin, $untouched);
        $this->assertFalse($before->historyBlockVisible, 'criterion 1');
        $this->assertSame([], $before->historyTabLabels, 'criterion 1');
        $this->assertSame([], $before->historyEntries, 'criterion 1');
        $this->assertFalse($empty->historyBlockVisible, 'criterion 1');

        $issues->update($admin, $related, [
            'notes' => 'Decoy note so display anchors are not journal ids.',
        ]);
        $decoy = Journal::query()->where('journalized_id', $related->id)->orderBy('id')->first();
        $this->assertInstanceOf(Journal::class, $decoy);

        $updated = $issues->update($admin, $host, [
            'status_id' => $world->inProgress->id,
            'done_ratio' => 30,
            'notes' => 'Shipped with *emphasis* in the note.',
        ]);
        $afterUpdate = $history->present($admin, $updated, true);
        $this->assertSame('✓ Successful update.', $afterUpdate->flashText, 'criterion 11');
        $this->assertSame(IssueHistoryPresenter::SUCCESSFUL_UPDATE, $afterUpdate->flashText, 'criterion 11');
        $this->assertSame('green', $afterUpdate->flashTone, 'criterion 11');

        app(IssueRelationService::class)->add($admin, $updated, $related, 'relates');
        $issues->update($notesPrivate, $updated->fresh(), [
            'notes' => 'Private fixture note for the security check.',
            'private_notes' => true,
        ]);

        $adminShow = $history->present($admin, $updated->fresh());
        $privateShow = $history->present($notesPrivate, $updated->fresh());
        $publicShow = $history->present($notesPublic, $updated->fresh());

        $this->assertTrue($adminShow->historyBlockVisible, 'criterion 2');
        $this->assertSame(['History', 'Notes', 'Property changes'], $adminShow->historyTabLabels, 'criterion 2');
        $this->assertNull($adminShow->flashText, 'criterion 11 flash is only for the update that just succeeded');
        $this->assertCount(3, $adminShow->historyEntries, 'criterion 3 and 4 and private note');

        $mixed = $adminShow->historyEntries[0];
        $relation = $adminShow->historyEntries[1];
        $private = $adminShow->historyEntries[2];
        $this->assertSame(['#1', '#2', '#3'], [
            $mixed->anchorLabel,
            $relation->anchorLabel,
            $private->anchorLabel,
        ], 'criterion 10');
        $this->assertNotSame($decoy->id, $mixed->journalId, 'criterion 10 anchor is display order');
        $this->assertGreaterThan($decoy->id, $mixed->journalId, 'criterion 10 anchor is display order');
        $this->assertTrue($mixed->hasNote, 'criterion 3');
        $this->assertNotSame([], $mixed->propertyChanges, 'criterion 3');
        $lines = array_map(static fn ($line) => $line->text, $mixed->propertyChanges);
        $this->assertContains('Status changed from New to In Progress', $lines, 'criterion 5');
        $this->assertContains('% Done changed from 0 to 30', $lines, 'criterion 5');
        $statusLine = $this->line($mixed, 'Status changed from New to In Progress');
        $doneLine = $this->line($mixed, '% Done changed from 0 to 30');
        $this->assertSame('Status changed from <em>New</em> to <em>In Progress</em>', $statusLine->html, 'criterion 6');
        $this->assertSame('% Done changed from <em>0</em> to <em>30</em>', $doneLine->html, 'criterion 6');
        $this->assertStringContainsString('<em>emphasis</em>', (string) $mixed->noteHtml, 'criterion 7');
        $this->assertStringNotContainsString('*emphasis*', (string) $mixed->noteHtml, 'criterion 7');
        $this->assertSame(['reaction', 'quote', 'edit', 'more'], $this->keys($mixed), 'criterion 8');
        $this->assertSame('thumbs-up', $mixed->actions[0]->label, 'criterion 8');
        $this->assertSame('quote', $mixed->actions[1]->label, 'criterion 8');
        $this->assertSame('edit', $mixed->actions[2]->label, 'criterion 8');
        $this->assertSame('pencil', $mixed->actions[2]->icon, 'criterion 8');
        $this->assertSame('⋯', $mixed->actions[3]->label, 'criterion 8');

        $this->assertFalse($relation->hasNote, 'criterion 4');
        $this->assertSame(
            'Related to Bug #'.$related->id.': Sample issue 2 added',
            $relation->propertyChanges[0]->text,
            'criterion 4',
        );
        $this->assertSame(['reaction', 'more'], $this->keys($relation), 'criterion 9');
        $this->assertNotContains('quote', $this->keys($relation), 'criterion 9');
        $this->assertNotContains('edit', $this->keys($relation), 'criterion 9');

        $this->assertTrue($adminShow->notesFieldsetVisible, 'criterion 12');
        $this->assertTrue($adminShow->privateNotesCheckboxVisible, 'criterion 12');
        $this->assertFalse($adminShow->privateNotesChecked, 'criterion 12');

        $this->assertStringContainsString('Private fixture note for the security check.', $this->blob($privateShow), 'criterion 13');
        $this->assertTrue($privateShow->privateNotesCheckboxVisible, 'criterion 15');
        $this->assertFalse($privateShow->privateNotesChecked, 'criterion 15');

        $this->assertStringNotContainsString('Private fixture note for the security check.', $this->blob($publicShow), 'criterion 14');
        $this->assertCount(2, $publicShow->historyEntries, 'criterion 14');
        $this->assertFalse($publicShow->privateNotesCheckboxVisible, 'criterion 15');
        $this->assertTrue($publicShow->notesFieldsetVisible, 'criterion 15');

        $stored = Journal::query()
            ->where('journalized_type', 'Issue')
            ->where('journalized_id', $host->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $stored);
        $status = JournalDetail::query()->where('journal_id', $stored[0]->id)->where('prop_key', 'status_id')->first();
        $done = JournalDetail::query()->where('journal_id', $stored[0]->id)->where('prop_key', 'done_ratio')->first();
        $this->assertInstanceOf(JournalDetail::class, $status);
        $this->assertInstanceOf(JournalDetail::class, $done);
        $this->assertSame('attr', $status->property);
        $this->assertSame((string) $world->newStatus->id, $status->old_value);
        $this->assertSame((string) $world->inProgress->id, $status->value);
        $this->assertSame('0', $done->old_value);
        $this->assertSame('30', $done->value);
        $this->assertFalse((bool) $stored[0]->private_notes);
        $relationDetail = JournalDetail::query()->where('journal_id', $stored[1]->id)->first();
        $this->assertInstanceOf(JournalDetail::class, $relationDetail);
        $this->assertSame('relation', $relationDetail->property);
        $this->assertSame('relates', $relationDetail->prop_key);
        $this->assertSame((string) $related->id, $relationDetail->value);
        $this->assertNull($stored[1]->notes);
        $this->assertTrue((bool) $stored[2]->private_notes);
        $this->assertSame('Private fixture note for the security check.', $stored[2]->notes);
    }

    public function test_private_journal_is_hidden_from_its_author_without_view_private_notes(): void
    {
        $world = DomainFixture::boot('author-notes');
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-author']);
        $author = $this->member($world, 'author_private', [
            'view_issues',
            'add_issue_notes',
            'set_notes_private',
        ]);
        $reader = $this->member($world, 'reader_private', [
            'view_issues',
            'view_private_notes',
        ]);
        $issues = app(IssueService::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Author hidden',
        ]);
        $issues->update($author, $issue, [
            'notes' => 'Only the private role should read this.',
            'private_notes' => true,
        ]);

        $history = app(IssueHistoryPresenter::class);
        $this->assertStringNotContainsString(
            'Only the private role should read this.',
            $this->blob($history->present($author, $issue->fresh())),
        );
        $this->assertStringContainsString(
            'Only the private role should read this.',
            $this->blob($history->present($reader, $issue->fresh())),
        );
    }

    public function test_private_flag_hides_property_changes_without_view_private_notes(): void
    {
        $world = DomainFixture::boot('private-diff');
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-diff']);
        $notesPublic = $this->member($world, 'notes_public_diff', [
            'view_issues',
            'add_issue_notes',
        ]);
        $issues = app(IssueService::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Hidden diff',
            'status_id' => $world->newStatus->id,
        ]);
        $issues->update($admin, $issue, [
            'status_id' => $world->inProgress->id,
            'private_notes' => true,
            'notes' => 'Status note stays private.',
        ]);

        $show = app(IssueHistoryPresenter::class)->present($notesPublic, $issue->fresh());
        $this->assertFalse($show->historyBlockVisible);
        $this->assertStringNotContainsString('In Progress', $this->blob($show));
        $this->assertStringNotContainsString('Status note stays private.', $this->blob($show));
    }

    public function test_note_private_and_relation_permissions_deny(): void
    {
        $world = DomainFixture::boot('deny-notes');
        $world->join();
        $editor = User::factory()->create(['login' => 'editor']);
        $role = Role::query()->create([
            'name' => 'Editor',
            'builtin' => 0,
            'assignable' => true,
            'permissions' => ['view_issues', 'edit_issues'],
            'issues_visibility' => 'default',
        ]);
        app(MembershipService::class)->assignRole($world->project, $editor, $role);
        $admin = User::factory()->create(['admin' => true, 'login' => 'admin-deny']);
        $issues = app(IssueService::class);
        $issue = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Editable',
        ]);
        $other = $issues->create($admin, $world->project, [
            'tracker_id' => $world->tracker->id,
            'subject' => 'Other',
        ]);

        try {
            $issues->update($editor, $issue, ['notes' => 'Not allowed']);
            $this->fail('add_issue_notes is required to store a note.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('add_issue_notes', $denied->permission);
        }

        try {
            $issues->update($editor, $issue, ['private_notes' => true, 'notes' => 'Secret']);
            $this->fail('A private note still requires add_issue_notes when the note is non-blank.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('add_issue_notes', $denied->permission);
        }

        try {
            $issues->update($world->user, $issue, ['private_notes' => true]);
            $this->fail('set_notes_private is required to mark a journal private.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('set_notes_private', $denied->permission);
        }

        try {
            app(IssueRelationService::class)->add($editor, $issue, $other, 'relates');
            $this->fail('manage_issue_relations is required to add a relation.');
        } catch (PermissionDeniedException $denied) {
            $this->assertSame('manage_issue_relations', $denied->permission);
        }

        $renamed = $issues->update($editor, $issue->fresh(), ['subject' => 'Renamed']);
        $this->assertSame('Renamed', $renamed->subject);
        $journal = Journal::query()->where('journalized_id', $issue->id)->where('user_id', $editor->id)->first();
        $this->assertInstanceOf(Journal::class, $journal);
        $this->assertNull($journal->notes);
        $detail = JournalDetail::query()->where('journal_id', $journal->id)->where('prop_key', 'subject')->first();
        $this->assertInstanceOf(JournalDetail::class, $detail);
        $this->assertSame('Editable', $detail->old_value);
        $this->assertSame('Renamed', $detail->value);
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

    private function line(JournalEntryView $entry, string $text): JournalPropertyLine
    {
        foreach ($entry->propertyChanges as $line) {
            if ($line->text === $text) {
                return $line;
            }
        }

        $this->fail('Missing property line: '.$text);
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
        $chunks = [$show->flashText ?? ''];
        foreach ($show->historyEntries as $entry) {
            $chunks[] = $entry->anchorLabel;
            $chunks[] = (string) $entry->noteText;
            $chunks[] = (string) $entry->noteHtml;
            foreach ($entry->propertyChanges as $line) {
                $chunks[] = $line->text;
                $chunks[] = $line->html;
            }
        }

        return implode("\n", $chunks);
    }
}
