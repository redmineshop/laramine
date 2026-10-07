<?php

namespace App\Domain\Notifications;

use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;

/**
 * Queues issue-added and issue-edited notifications after the row is stored.
 */
final class IssueNotifier
{
    public function __construct(
        private readonly NotifiedEventSetting $events,
        private readonly JournalEventClassifier $classifier,
        private readonly IssueRecipientResolver $recipients,
        private readonly MailIdentity $identity,
        private readonly OutboundMail $mail,
    ) {}

    public function added(User $actor, Issue $issue): void
    {
        if (! $this->events->allows(NotifiedEventCatalog::ISSUE_ADDED)) {
            return;
        }

        $issue->loadMissing(['project', 'tracker', 'status', 'author']);
        $createdOn = $issue->created_on;
        if (! $createdOn instanceof DateTimeInterface) {
            return;
        }

        $this->deliver(
            $actor,
            $issue,
            null,
            null,
            $this->subject($issue, true),
            $this->body($issue, null, null),
            $this->identity->messageId('issue', (int) $issue->id, $createdOn),
            [$this->identity->messageId('issue', (int) $issue->id, $createdOn)],
        );
    }

    public function edited(User $actor, Issue $issue, Journal $journal, ?int $previousAssigneeId): void
    {
        $journal->loadMissing('details');
        $enabled = $this->events->enabled();
        if (! $this->classifier->emits($journal, $enabled)) {
            return;
        }

        $issue->loadMissing(['project', 'tracker', 'status', 'author']);
        $createdOn = $journal->created_on;
        $issueCreated = $issue->created_on;
        if (! $issueCreated instanceof DateTimeInterface) {
            return;
        }

        $statusChanged = $this->classifier->specific($journal);
        $this->deliver(
            $actor,
            $issue,
            $journal,
            $previousAssigneeId,
            $this->subject($issue, in_array(NotifiedEventCatalog::ISSUE_STATUS_UPDATED, $statusChanged, true)),
            null,
            $this->identity->messageId('journal', (int) $journal->id, $createdOn),
            [$this->identity->messageId('issue', (int) $issue->id, $issueCreated)],
        );
    }

    /**
     * @param  list<string>  $references
     */
    private function deliver(
        User $actor,
        Issue $issue,
        ?Journal $journal,
        ?int $previousAssigneeId,
        string $subject,
        ?string $sharedBody,
        string $messageId,
        array $references,
    ): void {
        $project = $issue->project;
        if (! $project instanceof Project) {
            return;
        }

        $headers = $this->identity->commonHeaders((string) $actor->login);
        $headers['X-Redmine-Project'] = (string) $project->identifier;
        $headers['X-Redmine-Issue-Id'] = (string) $issue->id;
        $author = $issue->author;
        if ($author instanceof User && $author->login !== '') {
            $headers['X-Redmine-Issue-Author'] = $author->login;
        }
        $assignee = $issue->assignee()->first();
        if ($assignee instanceof User && ($assignee->type === User::TYPE_USER || $assignee->type === User::TYPE_GROUP) && $assignee->login !== '') {
            $headers['X-Redmine-Issue-Assignee'] = $assignee->login;
        }

        foreach ($this->recipients->recipients($actor, $issue, $previousAssigneeId, $journal) as $user) {
            $body = $sharedBody ?? $this->body($issue, $journal, $user);
            foreach ($this->recipients->addresses($user) as $address) {
                $this->mail->queue($address, $subject, $body, $messageId, $references, $headers);
            }
        }
    }

    private function subject(Issue $issue, bool $includeStatus): string
    {
        $project = $issue->project;
        $tracker = $issue->tracker;
        $status = $issue->status;
        $projectName = $project instanceof Project ? (string) $project->name : '';
        $trackerName = $tracker !== null ? (string) $tracker->name : '';
        $statusName = $status !== null ? (string) $status->name : '';
        $statusPrefix = $includeStatus && $statusName !== '' ? '('.$statusName.') ' : '';

        return '['.$projectName.' - '.$trackerName.' #'.$issue->id.'] '.$statusPrefix.(string) $issue->subject;
    }

    private function body(Issue $issue, ?Journal $journal, ?User $reader): string
    {
        $lines = [
            $this->subject($issue, true),
            'Project: '.($issue->project instanceof Project ? (string) $issue->project->identifier : ''),
            'Issue: '.$issue->id,
        ];
        if (! $journal instanceof Journal) {
            return implode("\n", $lines)."\n";
        }

        $lines[] = 'Journal: '.$journal->id;
        $notes = is_string($journal->notes) ? trim($journal->notes) : '';
        if ($notes === '') {
            return implode("\n", $lines)."\n";
        }

        $project = $issue->project;
        $show = ! (bool) $journal->private_notes
            || ($reader instanceof User && $project instanceof Project && $this->recipients->seesPrivateNote($reader, $project));
        if ($show) {
            $lines[] = 'Note: '.$notes;
        }

        return implode("\n", $lines)."\n";
    }
}
