<?php

namespace App\Domain\Attachments;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\DomainException;
use App\Domain\Issues\IssueJournalWriter;
use App\Domain\Notifications\IssueNotifier;
use App\Domain\PermissionDeniedException;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\Journal;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

/**
 * Upload tokens, container download, thumbnails, and attachment journals.
 *
 * A token is `{id}.{digest}` of an unbound row. Claiming it sets the
 * container and writes an attachment journal on the issue. Deleting a
 * container file writes the removal journal. Custom-field files stay on
 * the custom-field routes.
 */
final class AttachmentContainerService
{
    public function __construct(
        private readonly AttachmentService $files,
        private readonly AttachmentThumbnailRenderer $thumbnails,
        private readonly AttachmentThumbnails $images,
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $issues,
        private readonly IssueJournalWriter $journals,
        private readonly IssueNotifier $notifications,
        private readonly SettingValue $settings,
    ) {}

    /**
     * @return array{token: string, attachment: Attachment}
     */
    public function upload(User $actor, string $filename, string $contents, ?string $contentType): array
    {
        $this->assertActiveUser($actor);
        $attachment = $this->files->store($actor, $filename, $contents, $contentType);

        return [
            'token' => $attachment->id.'.'.$attachment->digest,
            'attachment' => $attachment,
        ];
    }

    public function claim(
        User $actor,
        string $token,
        ?int $issueId,
        ?int $journalId,
        ?string $filename,
        ?string $description,
    ): Attachment {
        $this->assertActiveUser($actor);
        [$issue, $journal] = $this->claimTarget($issueId, $journalId);
        $this->assertCanEdit($actor, $issue, $journal);
        $attachment = $this->findToken($token);

        $written = null;
        $fresh = DB::transaction(function () use ($actor, $attachment, $issue, $journal, $filename, $description, &$written): Attachment {
            $locked = $this->lockUnbound($attachment);
            $this->files->retitle($locked, $filename, $description);
            $container = $journal instanceof Journal ? $journal : $issue;
            $this->files->bind($locked, $container);
            $fresh = $locked->refresh();
            $written = $this->journals->recordAttachmentAdded($actor, $issue, (int) $fresh->id, (string) $fresh->filename);

            return $fresh;
        });
        if ($written instanceof Journal) {
            $this->notifications->edited($actor, $issue, $written, null);
        }

        return $fresh;
    }

    public function delete(User $actor, Attachment $attachment): void
    {
        $this->assertActiveUser($actor);
        $this->assertContainerFile($attachment);
        [$issue, $journal] = $this->editableIssue($attachment);
        $this->assertCanEdit($actor, $issue, $journal);

        $written = null;
        DB::transaction(function () use ($actor, $attachment, $issue, &$written): void {
            $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
            if (! $locked instanceof Attachment) {
                throw new DomainException('Attachment does not exist.');
            }
            $this->assertContainerFile($locked);
            $written = $this->journals->recordAttachmentRemoved($actor, $issue, (int) $locked->id, (string) $locked->filename);
            $this->thumbnails->forget($locked);
            $this->files->forgetFile($locked);
            $locked->delete();
        });
        if ($written instanceof Journal) {
            $this->notifications->edited($actor, $issue, $written, null);
        }
    }

    public function download(?User $actor, Attachment $attachment): AttachmentDownload
    {
        $this->assertContainerFile($attachment);
        $this->assertCanRead($actor, $attachment);
        $path = $this->files->absolutePath($attachment);
        if (! is_file($path)) {
            throw new DomainException('Attachment file is not stored.');
        }
        $this->countDownload($attachment);
        $contentType = $this->contentType($attachment);

        return new AttachmentDownload($path, (string) $attachment->filename, $contentType, $this->disposition($contentType));
    }

    public function thumbnail(?User $actor, Attachment $attachment, ?int $requestedSize): AttachmentDownload
    {
        if (! $this->settings->thumbnailsEnabled()) {
            throw new DomainException('Thumbnails are disabled.');
        }
        $this->assertContainerFile($attachment);
        $this->assertCanRead($actor, $attachment);
        if (! $this->images->isImage((string) $attachment->filename)) {
            throw new DomainException('Attachment is not an image.');
        }
        $edge = $this->thumbnails->edge($requestedSize);
        $path = $this->thumbnails->render($attachment, $edge);

        return new AttachmentDownload($path, (string) $attachment->filename, 'image/png', 'inline');
    }

    private function assertActiveUser(User $actor): void
    {
        if ($actor->type !== User::TYPE_USER || ! $actor->isActive()) {
            throw new PermissionDeniedException('view_issues');
        }
    }

    /**
     * @return array{0: Issue, 1: ?Journal}
     */
    private function claimTarget(?int $issueId, ?int $journalId): array
    {
        if (($issueId === null) === ($journalId === null)) {
            throw new DomainException('Attachment needs an issue or a journal.');
        }
        if ($journalId !== null) {
            $journal = Journal::query()->find($journalId);
            if (! $journal instanceof Journal || (string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
                throw new DomainException('Journal cannot own an attachment.');
            }
            $issue = Issue::query()->find($journal->journalized_id);
            if (! $issue instanceof Issue) {
                throw new DomainException('Journal cannot own an attachment.');
            }

            return [$issue, $journal];
        }
        $issue = Issue::query()->find($issueId);
        if (! $issue instanceof Issue) {
            throw new DomainException('Issue does not exist.');
        }

        return [$issue, null];
    }

    /**
     * @return array{0: Issue, 1: ?Journal}
     */
    private function editableIssue(Attachment $attachment): array
    {
        $type = (string) $attachment->container_type;
        $id = (int) $attachment->container_id;
        if ($type === 'Issue') {
            $issue = Issue::query()->find($id);
            if (! $issue instanceof Issue) {
                throw new DomainException('Attachment is not attached.');
            }

            return [$issue, null];
        }
        if ($type === 'Journal') {
            $journal = Journal::query()->find($id);
            if (! $journal instanceof Journal || (string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
                throw new DomainException('Attachment is not attached.');
            }
            $issue = Issue::query()->find($journal->journalized_id);
            if (! $issue instanceof Issue) {
                throw new DomainException('Attachment is not attached.');
            }

            return [$issue, $journal];
        }
        if ($type === 'Project' || $type === 'Version') {
            throw new DomainException('Attachment delete is not available for this container.');
        }

        throw new DomainException('Attachment is not attached.');
    }

    private function assertCanEdit(User $actor, Issue $issue, ?Journal $journal): void
    {
        $project = $issue->project;
        if (! $project instanceof Project) {
            throw new DomainException('Issue has no project.');
        }
        if (! $this->issues->canSee($actor, $issue)) {
            throw new PermissionDeniedException('view_issues');
        }
        if ($journal instanceof Journal) {
            if ($journal->private_notes && ! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
                throw new PermissionDeniedException('view_private_notes');
            }
            if ($this->permissions->allowed($actor, 'edit_issue_notes', $project)) {
                return;
            }
            if ((int) $journal->user_id === (int) $actor->id
                && $this->permissions->allowed($actor, 'edit_own_issue_notes', $project)) {
                return;
            }
            throw new PermissionDeniedException('edit_issue_notes');
        }
        if ($this->permissions->allowed($actor, 'edit_issues', $project, $issue->tracker)) {
            return;
        }
        if ((int) $issue->author_id === (int) $actor->id
            && $this->permissions->allowed($actor, 'edit_own_issues', $project)) {
            return;
        }
        throw new PermissionDeniedException('edit_issues');
    }

    private function assertCanRead(?User $actor, Attachment $attachment): void
    {
        $type = (string) $attachment->container_type;
        $id = (int) $attachment->container_id;
        if ($type === 'Issue') {
            $issue = Issue::query()->with('project')->find($id);
            if (! $issue instanceof Issue || ! $this->issues->canSee($actor, $issue)) {
                throw new PermissionDeniedException('view_issues');
            }

            return;
        }
        if ($type === 'Journal') {
            $journal = Journal::query()->find($id);
            if (! $journal instanceof Journal || (string) $journal->journalized_type !== IssueJournalWriter::JOURNALIZED_ISSUE) {
                throw new DomainException('Attachment is not attached.');
            }
            $issue = Issue::query()->with('project')->find($journal->journalized_id);
            if (! $issue instanceof Issue || ! $this->issues->canSee($actor, $issue)) {
                throw new PermissionDeniedException('view_issues');
            }
            $project = $issue->project;
            if ($journal->private_notes && ! $this->permissions->allowed($actor, 'view_private_notes', $project)) {
                throw new PermissionDeniedException('view_private_notes');
            }

            return;
        }
        if ($type === 'Project') {
            $project = Project::query()->find($id);
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'view_files', $project)) {
                throw new PermissionDeniedException('view_files');
            }

            return;
        }
        if ($type === 'Version') {
            $version = Version::query()->with('project')->find($id);
            $project = $version instanceof Version ? $version->project : null;
            if (! $project instanceof Project || ! $this->permissions->allowed($actor, 'view_files', $project)) {
                throw new PermissionDeniedException('view_files');
            }

            return;
        }

        throw new DomainException('Attachment is not attached.');
    }

    private function assertContainerFile(Attachment $attachment): void
    {
        if ($this->isUnbound($attachment)) {
            throw new DomainException('Attachment is not attached.');
        }
        $fieldIds = CustomField::query()->where('field_format', 'attachment')->pluck('id');
        if ($fieldIds->isEmpty()) {
            return;
        }
        $used = CustomValue::query()
            ->whereIn('custom_field_id', $fieldIds->all())
            ->where('value', (string) $attachment->id)
            ->exists();
        if ($used) {
            throw new DomainException('Attachment is a custom field file.');
        }
    }

    private function findToken(string $token): Attachment
    {
        if (preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $match) !== 1) {
            throw new DomainException('Attachment token is invalid.');
        }
        $attachment = Attachment::query()->find((int) $match[1]);
        $stored = $attachment instanceof Attachment ? (string) $attachment->digest : '';
        if (! $attachment instanceof Attachment || strlen($stored) !== strlen($match[2]) || ! hash_equals($stored, $match[2]) || ! $this->isUnbound($attachment)) {
            throw new DomainException('Attachment token is invalid.');
        }

        return $attachment;
    }

    private function lockUnbound(Attachment $attachment): Attachment
    {
        $locked = Attachment::query()->whereKey($attachment->id)->lockForUpdate()->first();
        if (! $locked instanceof Attachment || ! $this->isUnbound($locked)) {
            throw new DomainException('Attachment token is invalid.');
        }
        if (! hash_equals((string) $attachment->digest, (string) $locked->digest)) {
            throw new DomainException('Attachment token is invalid.');
        }

        return $locked;
    }

    private function isUnbound(Attachment $attachment): bool
    {
        $type = $attachment->container_type;

        return ($type === null || $type === '') && $attachment->container_id === null;
    }

    private function countDownload(Attachment $attachment): void
    {
        $type = (string) $attachment->container_type;
        if ($type !== 'Project' && $type !== 'Version') {
            return;
        }
        $attachment->downloads = (int) $attachment->downloads + 1;
        $attachment->save();
    }

    private function contentType(Attachment $attachment): string
    {
        $stored = $attachment->content_type;

        return is_string($stored) && $stored !== '' ? $stored : 'application/octet-stream';
    }

    private function disposition(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if (
            $type === 'application/pdf'
            || str_starts_with($type, 'image/')
            || str_starts_with($type, 'text/')
            || str_starts_with($type, 'audio/')
            || str_starts_with($type, 'video/')
        ) {
            return 'inline';
        }

        return 'attachment';
    }
}
