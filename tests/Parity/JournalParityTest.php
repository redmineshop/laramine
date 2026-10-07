<?php

namespace Tests\Parity;

use App\Domain\DomainException;
use App\Domain\Issues\History\IssueHistoryPresenter;
use App\Domain\Issues\History\JournalEntryView;
use App\Domain\Issues\JournalNoteService;
use App\Domain\PermissionDeniedException;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares visible issue journals and rendered detail lines to the shared pin.
 *
 * Repository, git, and SCM history stay out of this comparison.
 */
class JournalParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_journals_match_the_pin_for_each_actor(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('history.json');
        $issues = $expected['issues'] ?? null;
        $this->assertIsArray($issues);
        $history = app(IssueHistoryPresenter::class);

        foreach ($issues as $issueCase) {
            $this->assertIsArray($issueCase);
            $issue = $this->issue($this->intField($issueCase, 'issue_id'));
            $actors = $issueCase['actors'] ?? null;
            $this->assertIsArray($actors);
            foreach ($actors as $actorCase) {
                $this->assertIsArray($actorCase);
                $login = $this->stringField($actorCase, 'login');
                $show = $history->present($this->actor($login), $issue);
                $label = 'issue '.$issue->id.' '.$login;
                $this->assertSame($this->journals($actorCase), $this->actualJournals($show->historyEntries), $label);
                $this->assertSame($this->intList($actorCase, 'notes_journal_ids'), $this->ids($show->notesEntries), $label.' notes');
                $this->assertSame($this->intList($actorCase, 'property_journal_ids'), $this->ids($show->propertyChangeEntries), $label.' properties');
                $this->assertPropertyTabCopiesHistory($show->historyEntries, $show->propertyChangeEntries, $label);
            }
        }
    }

    public function test_note_quote_edit_and_delete_match_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation('notes.json');
        $steps = $expected['steps'] ?? null;
        $this->assertIsArray($steps);
        $notes = app(JournalNoteService::class);

        foreach ($steps as $step) {
            $this->assertIsArray($step);
            $action = $this->stringField($step, 'action');
            $login = $this->stringField($step, 'login');
            $journal = $this->journal($this->intField($step, 'journal_id'));
            $label = $action.' '.$login.' '.$journal->id;

            if ($action === 'deny') {
                $this->assertDenied($notes, $login, $journal, $step, $label);

                continue;
            }
            if ($action === 'quote') {
                $created = $notes->quote($this->actor($login), $journal);
                $this->assertSame($this->stringField($step, 'notes'), (string) $created->notes, $label);
                $this->assertSame($this->boolField($step, 'private_notes'), (bool) $created->private_notes, $label);
                $this->assertSame($this->intField($step, 'user_id'), (int) $created->user_id, $label);
                $this->assertNotSame((int) $journal->id, (int) $created->id, $label);

                continue;
            }
            if ($action === 'edit') {
                $updated = $notes->edit($this->actor($login), $journal, $this->stringField($step, 'notes'));
                $this->assertSame($this->stringField($step, 'notes'), (string) $updated->notes, $label);
                $this->assertSame($this->intField($step, 'user_id'), (int) $updated->user_id, $label);
                $this->assertSame($this->intField($step, 'updated_by_id'), (int) $updated->updated_by_id, $label);
                $this->assertSame($this->boolField($step, 'private_notes'), (bool) $updated->private_notes, $label);
                $this->assertSame($this->intField($step, 'detail_count'), $updated->details()->count(), $label);

                continue;
            }
            if ($action === 'delete') {
                $result = $notes->delete($this->actor($login), $journal);
                if ($this->boolField($step, 'removed')) {
                    $this->assertNull($result, $label);
                    $this->assertNull(Journal::query()->find($journal->id), $label);

                    continue;
                }
                $this->assertInstanceOf(Journal::class, $result, $label);
                $this->assertNull($result->notes, $label);
                $this->assertSame($this->intField($step, 'user_id'), (int) $result->user_id, $label);
                $this->assertSame($this->intField($step, 'updated_by_id'), (int) $result->updated_by_id, $label);
                $this->assertSame($this->intField($step, 'detail_count'), $result->details()->count(), $label);

                continue;
            }

            $this->fail('Unknown journal note action: '.$action);
        }
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Journals and private notes \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/JournalParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/journals/history.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/journals/notes.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertMatchesRegularExpression('/^\| Time entries and attachments \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/TimeEntryParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/AttachmentParityTest.php', $checklist);
    }

    /**
     * @param  array<mixed>  $step
     */
    private function assertDenied(JournalNoteService $notes, string $login, Journal $journal, array $step, string $label): void
    {
        $op = $this->stringField($step, 'op');
        try {
            if ($op === 'quote') {
                $notes->quote($this->actor($login), $journal);
            } elseif ($op === 'edit') {
                $notes->edit($this->actor($login), $journal, 'Denied rewrite.');
            } elseif ($op === 'delete') {
                $notes->delete($this->actor($login), $journal);
            } else {
                $this->fail($label);
            }
            $this->fail($label);
        } catch (PermissionDeniedException $denied) {
            $this->assertSame($this->stringField($step, 'permission'), $denied->permission, $label);
        } catch (DomainException $exception) {
            $this->assertSame($this->stringField($step, 'message'), $exception->getMessage(), $label);
        }
    }

    /**
     * @param  list<JournalEntryView>  $history
     * @param  list<JournalEntryView>  $properties
     */
    private function assertPropertyTabCopiesHistory(array $history, array $properties, string $label): void
    {
        $byId = [];
        foreach ($history as $entry) {
            $byId[$entry->journalId] = $entry;
        }
        foreach ($properties as $entry) {
            $source = $byId[$entry->journalId] ?? null;
            $this->assertInstanceOf(JournalEntryView::class, $source, $label);
            $this->assertNull($entry->noteText, $label);
            $this->assertSame($this->lines($source), $this->lines($entry), $label);
            $keys = [];
            foreach ($entry->actions as $action) {
                $keys[] = $action->key;
            }
            $this->assertSame(['reaction'], $keys, $label);
        }
    }

    /**
     * @param  array<mixed>  $actorCase
     * @return list<array<string, mixed>>
     */
    private function journals(array $actorCase): array
    {
        $journals = $actorCase['journals'] ?? null;
        $this->assertIsArray($journals);
        $rows = [];
        foreach ($journals as $journal) {
            $this->assertIsArray($journal);
            $rows[] = $journal;
        }

        return $rows;
    }

    /**
     * @param  list<JournalEntryView>  $entries
     * @return list<array<string, mixed>>
     */
    private function actualJournals(array $entries): array
    {
        $rows = [];
        $index = 0;
        foreach ($entries as $entry) {
            $index++;
            $quote = false;
            $edit = false;
            $delete = false;
            foreach ($entry->actions as $action) {
                $quote = $quote || $action->key === 'quote';
                $edit = $edit || $action->key === 'edit';
                foreach ($action->menuItems as $item) {
                    $delete = $delete || $item->key === 'delete';
                }
            }
            $rows[] = [
                'journal_id' => $entry->journalId,
                'index' => $index,
                'anchor' => $entry->anchorLabel,
                'href' => $entry->anchorHref,
                'notes' => $entry->noteText,
                'private_notes' => $entry->privateNotes,
                'quote' => $quote,
                'edit' => $edit,
                'delete' => $delete,
                'lines' => $this->lines($entry),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{text: string, html: string}>
     */
    private function lines(JournalEntryView $entry): array
    {
        $lines = [];
        foreach ($entry->propertyChanges as $line) {
            $lines[] = ['text' => $line->text, 'html' => $line->html];
        }

        return $lines;
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
     * @return array<string, mixed>
     */
    private function expectation(string $file): array
    {
        $path = Redmine701Fixture::directory().'/expectations/journals/'.$file;
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    private function actor(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user, $login);

        return $user;
    }

    private function issue(int $id): Issue
    {
        $issue = Issue::query()->find($id);
        $this->assertInstanceOf(Issue::class, $issue);

        return $issue;
    }

    private function journal(int $id): Journal
    {
        $journal = Journal::query()->find($id);
        $this->assertInstanceOf(Journal::class, $journal);

        return $journal;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        $this->assertIsString($value, $key);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        $this->assertIsInt($value, $key);

        return $value;
    }

    /**
     * @param  array<mixed>  $row
     * @return list<int>
     */
    private function intList(array $row, string $key): array
    {
        $value = $row[$key] ?? null;
        $this->assertIsArray($value, $key);
        $ids = [];
        foreach ($value as $item) {
            $this->assertIsInt($item);
            $ids[] = $item;
        }

        return $ids;
    }

    /**
     * @param  array<mixed>  $row
     */
    private function boolField(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        $this->assertIsBool($value, $key);

        return $value;
    }
}
