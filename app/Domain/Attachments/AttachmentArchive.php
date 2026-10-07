<?php

namespace App\Domain\Attachments;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\AttachmentArchiveLimitException;
use App\Domain\DomainException;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Issues\JournalNoteAccess;
use App\Domain\PermissionDeniedException;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\JournalDetail;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Model;
use ZipArchive;

/**
 * Builds the "Download all files" zip for an issue or a journal.
 *
 * The history menu offers this when the container has more than one
 * attachment and names the zip `issue-{id}.zip` or `journal-{id}.zip`.
 * One file is not an archive on that path. The container route allows one
 * readable file, names the zip `{type}-{id}-attachments.zip`, and stops
 * when the readable bytes exceed `bulk_download_max_size`.
 */
final class AttachmentArchive
{
    public function __construct(
        private readonly IssueVisibility $issues,
        private readonly JournalNoteAccess $notes,
        private readonly AttachmentService $files,
        private readonly ZipEntryNames $names,
        private readonly PermissionService $permissions,
        private readonly SettingValue $settings,
    ) {}

    public function downloadAll(User $actor, Model $container): AttachmentZip
    {
        [$type, $id] = $this->authorize($actor, $container);
        $attachments = $this->attachments($type, $id);
        if (count($attachments) < 2) {
            throw new DomainException('Download all files needs more than one attachment.');
        }

        return $this->zip(strtolower($type).'-'.$id.'.zip', $attachments, 2);
    }

    /**
     * Zip every readable file on an issue, journal, project, version, news row, or document.
     *
     * Journal files are the attachment ids named by that journal's details.
     * A private note does not hide those files when the issue itself is visible.
     * News needs `view_news`. A document needs `view_documents`. The zip name
     * is the class name in lower case, so a document is `document-{id}-attachments.zip`.
     * There is no `files` object type: project and version rows are those containers.
     * Messages and wiki pages are not served here.
     */
    public function downloadBundle(?User $actor, string $objectType, int $objectId): AttachmentZip
    {
        [$label, $attachments] = $this->bundle($actor, $objectType, $objectId);
        if ($attachments === []) {
            throw new DomainException('Attachment archive is empty.');
        }
        $total = 0;
        foreach ($attachments as $attachment) {
            $total += (int) $attachment->filesize;
        }
        if ($total > $this->settings->bulkDownloadMaxBytes()) {
            throw new AttachmentArchiveLimitException('Attachment archive is larger than the maximum size.');
        }

        return $this->zip(strtolower($label).'-'.$objectId.'-attachments.zip', $attachments, 1);
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    private function zip(string $filename, array $attachments, int $firstDuplicate): AttachmentZip
    {
        $filenames = [];
        foreach ($attachments as $attachment) {
            $filenames[] = (string) $attachment->filename;
        }
        $entries = $this->names->unique($filenames, $firstDuplicate);

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

        return new AttachmentZip($filename, $contents);
    }

    /**
     * @return array{0: string, 1: list<Attachment>}
     */
    private function bundle(?User $actor, string $objectType, int $objectId): array
    {
        if ($objectType === 'issues') {
            $issue = Issue::query()->find($objectId);
            if (! $issue instanceof Issue) {
                throw new DomainException('Attachment container does not exist.');
            }
            if (! $this->issues->canSee($actor, $issue)) {
                throw new PermissionDeniedException('view_issues');
            }

            return ['Issue', $this->readable($this->containerAttachments('Issue', $objectId))];
        }

        if ($objectType === 'journals') {
            $journal = Journal::query()->find($objectId);
            if (! $journal instanceof Journal) {
                throw new DomainException('Attachment container does not exist.');
            }
            if ((string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
                throw new DomainException('Journal attachments are only downloaded for an issue.');
            }
            $issue = Issue::query()->find($journal->journalized_id);
            if (! $issue instanceof Issue || ! $this->issues->canSee($actor, $issue)) {
                throw new PermissionDeniedException('view_issues');
            }

            return ['Journal', $this->readable($this->journalAttachments($journal))];
        }

        if ($objectType === 'projects') {
            $project = Project::query()->find($objectId);
            if (! $project instanceof Project) {
                throw new DomainException('Attachment container does not exist.');
            }
            if (! $this->permissions->allowed($actor, 'view_files', $project)) {
                throw new PermissionDeniedException('view_files');
            }

            return ['Project', $this->readable($this->containerAttachments('Project', $objectId))];
        }

        if ($objectType === 'versions') {
            $version = Version::query()->with('project')->find($objectId);
            $project = $version instanceof Version ? $version->project : null;
            if (! $version instanceof Version || ! $project instanceof Project) {
                throw new DomainException('Attachment container does not exist.');
            }
            if (! $this->permissions->allowed($actor, 'view_issues', $project)) {
                throw new PermissionDeniedException('view_issues');
            }
            if (! $this->permissions->allowed($actor, 'view_files', $project)) {
                throw new PermissionDeniedException('view_files');
            }

            return ['Version', $this->readable($this->containerAttachments('Version', $objectId))];
        }

        if ($objectType === 'news') {
            $news = News::query()->with('project')->find($objectId);
            $project = $news instanceof News ? $news->project : null;
            if (! $news instanceof News || ! $project instanceof Project) {
                throw new DomainException('Attachment container does not exist.');
            }
            if (! $this->permissions->allowed($actor, 'view_news', $project)) {
                throw new PermissionDeniedException('view_news');
            }

            return ['News', $this->readable($this->containerAttachments('News', $objectId))];
        }

        if ($objectType === 'documents') {
            $document = Document::query()->with('project')->find($objectId);
            $project = $document instanceof Document ? $document->project : null;
            if (! $document instanceof Document || ! $project instanceof Project) {
                throw new DomainException('Attachment container does not exist.');
            }
            if (! $this->permissions->allowed($actor, 'view_documents', $project)) {
                throw new PermissionDeniedException('view_documents');
            }

            return ['Document', $this->readable($this->containerAttachments('Document', $objectId))];
        }

        throw new DomainException('This record has no attachment archive.');
    }

    /**
     * @param  list<Attachment>  $attachments
     * @return list<Attachment>
     */
    private function readable(array $attachments): array
    {
        $rows = [];
        foreach ($attachments as $attachment) {
            if ($this->isReadable($attachment)) {
                $rows[] = $attachment;
            }
        }

        return $rows;
    }

    /**
     * @return list<Attachment>
     */
    private function containerAttachments(string $type, int $id): array
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

    /**
     * @return list<Attachment>
     */
    private function journalAttachments(Journal $journal): array
    {
        $ids = [];
        foreach (
            JournalDetail::query()
                ->where('journal_id', $journal->id)
                ->where('property', 'attachment')
                ->orderBy('id')
                ->get() as $detail
        ) {
            $value = $detail->getAttribute('value');
            if (! is_string($value) || $value === '') {
                continue;
            }
            $key = $detail->getAttribute('prop_key');
            if (is_string($key) && preg_match('/^\d+$/', $key) === 1) {
                $ids[] = (int) $key;
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            $attachment = Attachment::query()->find($id);
            if ($attachment instanceof Attachment) {
                $rows[] = $attachment;
            }
        }

        return $rows;
    }

    private function isReadable(Attachment $attachment): bool
    {
        try {
            $path = $this->files->absolutePath($attachment);
        } catch (DomainException) {
            return false;
        }

        return is_file($path) && is_readable($path);
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
