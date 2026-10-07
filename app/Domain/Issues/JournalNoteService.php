<?php

namespace App\Domain\Issues;

use App\Domain\DomainException;
use App\Domain\Notifications\IssueNotifier;
use App\Domain\PermissionDeniedException;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Quotes, edits, and deletes an issue journal note.
 *
 * Permissions match the history markers. Quote requires add_issue_notes.
 * Edit and delete require edit_issue_notes, or edit_own_issue_notes when
 * journals.user_id is the actor. A private journal also requires
 * view_private_notes. A journal with no note text is rejected.
 */
final class JournalNoteService
{
    public function __construct(
        private readonly JournalNoteAccess $access,
        private readonly JournalQuoteText $quotes,
        private readonly IssueJournalWriter $journals,
        private readonly IssueNotifier $notifications,
    ) {}

    public function quote(User $actor, Journal $journal): Journal
    {
        [$issue, $project] = $this->host($journal);
        $this->assertVisible($actor, $project, $journal);
        $this->requireNote($journal, 'quote');
        if (! $this->access->canQuote($actor, $project)) {
            throw new PermissionDeniedException('add_issue_notes');
        }

        $created = DB::transaction(function () use ($actor, $journal, $issue, $project): Journal {
            $locked = $this->lock($journal);
            $this->assertVisible($actor, $project, $locked);
            $source = $this->requireNote($locked, 'quote');
            $author = $locked->user;
            if (! $author instanceof User) {
                throw new DomainException('Journal has no author.');
            }
            $issue->touch();

            return $this->journals->recordNote(
                $actor,
                $issue,
                $this->quotes->render(
                    $this->quotes->authorName($author->firstname, $author->lastname, $author->login),
                    $source,
                ),
                (bool) $locked->private_notes,
            );
        });
        $this->notifications->edited($actor, $issue, $created, null);

        return $created;
    }

    public function edit(User $actor, Journal $journal, string $notes): Journal
    {
        $trimmed = trim($notes);
        if ($trimmed === '') {
            throw new DomainException('Journal note text cannot be blank.');
        }

        [$issue, $project] = $this->host($journal);
        $this->assertVisible($actor, $project, $journal);
        $this->requireNote($journal, 'edit');
        $this->assertCanEdit($actor, $project, $journal);

        return DB::transaction(function () use ($actor, $journal, $issue, $project, $trimmed): Journal {
            $locked = $this->lock($journal);
            $this->assertVisible($actor, $project, $locked);
            $current = $this->requireNote($locked, 'edit');
            if (trim($current) === $trimmed) {
                return $locked;
            }
            $this->assertCanEdit($actor, $project, $locked);
            $issue->touch();

            return $this->journals->replaceNote($actor, $locked, $trimmed);
        });
    }

    /**
     * Removes a notes-only journal. A journal that also has details keeps the
     * row and those details, with notes cleared.
     */
    public function delete(User $actor, Journal $journal): ?Journal
    {
        [$issue, $project] = $this->host($journal);
        $this->assertVisible($actor, $project, $journal);
        $this->requireNote($journal, 'delete');
        $this->assertCanEdit($actor, $project, $journal);

        return DB::transaction(function () use ($actor, $journal, $issue, $project): ?Journal {
            $locked = $this->lock($journal);
            $this->assertVisible($actor, $project, $locked);
            $this->requireNote($locked, 'delete');
            $this->assertCanEdit($actor, $project, $locked);
            $issue->touch();

            return $this->journals->removeNote($actor, $locked);
        });
    }

    /**
     * @return array{Issue, Project}
     */
    private function host(Journal $journal): array
    {
        if ((string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
            throw new DomainException('Journal is not an issue journal.');
        }

        $issue = Issue::query()->with('project')->find($journal->journalized_id);
        if (! $issue instanceof Issue) {
            throw new DomainException('Journal issue does not exist.');
        }
        $project = $issue->project;
        if (! $project instanceof Project) {
            throw new DomainException('Issue has no project.');
        }

        return [$issue, $project];
    }

    private function lock(Journal $journal): Journal
    {
        $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->first();
        if (! $locked instanceof Journal) {
            throw new DomainException('Journal does not exist.');
        }

        return $locked;
    }

    private function assertVisible(User $actor, Project $project, Journal $journal): void
    {
        if ($this->access->canView($actor, $project, (bool) $journal->private_notes)) {
            return;
        }

        throw new PermissionDeniedException('view_private_notes');
    }

    private function assertCanEdit(User $actor, Project $project, Journal $journal): void
    {
        if ($this->access->canEdit($actor, $project, $journal)) {
            return;
        }

        throw new PermissionDeniedException('edit_issue_notes');
    }

    private function requireNote(Journal $journal, string $action): string
    {
        if (! is_string($journal->notes) || trim($journal->notes) === '') {
            throw new DomainException('Journal has no note to '.$action.'.');
        }

        return $journal->notes;
    }
}
