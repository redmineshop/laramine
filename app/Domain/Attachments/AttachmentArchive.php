<?php

namespace App\Domain\Attachments;

use App\Domain\Acl\IssueVisibility;
use App\Domain\DomainException;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\JournalNoteAccess;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use ZipArchive;

/**
 * Builds the "Download all files" zip for an issue or a journal.
 *
 * The menu offers this when the container has more than one attachment.
 * One file is not an archive. The caller still goes through this service,
 * which rejects that case. There is no HTTP route.
 */
final class AttachmentArchive
{
    public function __construct(
        private readonly IssueVisibility $issues,
        private readonly JournalNoteAccess $notes,
        private readonly AttachmentService $files,
        private readonly ZipEntryNames $names,
    ) {}

    public function downloadAll(User $actor, Model $container): AttachmentZip
    {
        [$type, $id] = $this->authorize($actor, $container);
        $attachments = $this->attachments($type, $id);
        if (count($attachments) < 2) {
            throw new DomainException('Download all files needs more than one attachment.');
        }

        $filenames = [];
        foreach ($attachments as $attachment) {
            $filenames[] = (string) $attachment->filename;
        }
        $entries = $this->names->unique($filenames);

        $path = tempnam(sys_get_temp_dir(), 'laramine-zip-');
        if ($path === false) {
            throw new DomainException('Attachment archive could not be created.');
        }
        if (is_file($path)) {
            unlink($path);
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE) !== true) {
                throw new DomainException('Attachment archive could not be created.');
            }
            foreach ($attachments as $index => $attachment) {
                $bytes = $this->bytes($attachment);
                if ($zip->addFromString($entries[$index], $bytes) !== true) {
                    $zip->close();
                    throw new DomainException('Attachment archive could not be created.');
                }
            }
            if ($zip->close() !== true) {
                throw new DomainException('Attachment archive could not be created.');
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new DomainException('Attachment archive could not be created.');
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        return new AttachmentZip(strtolower($type).'-'.$id.'.zip', $contents);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function authorize(User $actor, Model $container): array
    {
        if ($container instanceof Issue) {
            if (! $this->issues->canSee($actor, $container)) {
                throw new PermissionDeniedException('view_issues');
            }
            $id = $container->getKey();
            if (! is_numeric($id)) {
                throw new DomainException('Save the record before downloading its files.');
            }

            return ['Issue', (int) $id];
        }

        if ($container instanceof Journal) {
            return $this->authorizeJournal($actor, $container);
        }

        throw new DomainException('This record has no attachment archive.');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function authorizeJournal(User $actor, Journal $journal): array
    {
        if ((string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
            throw new DomainException('Journal attachments are only downloaded for an issue.');
        }
        $issue = Issue::query()->find($journal->journalized_id);
        if (! $issue instanceof Issue || ! $this->issues->canSee($actor, $issue)) {
            throw new PermissionDeniedException('view_issues');
        }
        $project = $issue->project;
        if (! $project instanceof Project) {
            throw new PermissionDeniedException('view_issues');
        }
        if (! $this->notes->canView($actor, $project, (bool) $journal->private_notes)) {
            throw new PermissionDeniedException('view_private_notes');
        }
        $id = $journal->getKey();
        if (! is_numeric($id)) {
            throw new DomainException('Save the record before downloading its files.');
        }

        return ['Journal', (int) $id];
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(string $type, int $id): array
    {
        $rows = [];
        foreach (
            Attachment::query()
                ->where('container_type', $type)
                ->where('container_id', $id)
                ->orderBy('created_on')
                ->orderBy('id')
                ->get() as $row
        ) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function bytes(Attachment $attachment): string
    {
        $path = $this->files->absolutePath($attachment);
        if (! is_file($path)) {
            throw new DomainException('Attachment file is not stored.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new DomainException('Attachment file is not stored.');
        }

        return $bytes;
    }
}
