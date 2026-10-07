<?php

namespace App\Domain\CustomFields;

use App\Domain\Acl\IssueVisibility;
use App\Domain\Acl\PermissionService;
use App\Domain\Acl\TimeEntryVisibility;
use App\Domain\Attachments\AttachmentService;
use App\Domain\CustomFields\Formats\LinkFormat;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\CustomField;
use App\Models\CustomValue;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Authorized read of an attachment or link custom value.
 *
 * The actor must be allowed to see the host record and the field. A guest is
 * a null actor and uses the Anonymous role. Issue hosts use issue visibility.
 * Project and version hosts use `view_project`. Time entries use
 * `view_time_entries` and `time_entries_visibility`. A user host is visible to
 * that user and to an active admin. A group host is visible to an active
 * admin. `users_visibility` is not applied. A hidden field uses the denial
 * token `custom_field`, which is not a catalog permission name.
 *
 * Download increments `attachments.downloads`. Clearing the custom value
 * removes the row, so the file is no longer served. The link URL is resolved
 * and is not requested.
 */
final class CustomFieldAssetAccess
{
    public const HIDDEN_FIELD = 'custom_field';

    public function __construct(
        private readonly FieldFormatRegistry $formats,
        private readonly CustomFieldScope $scope,
        private readonly CustomFieldVisibility $visibility,
        private readonly CustomizedContext $context,
        private readonly PermissionService $permissions,
        private readonly IssueVisibility $issues,
        private readonly TimeEntryVisibility $timeEntries,
        private readonly AttachmentService $files,
    ) {}

    public function download(?User $actor, Attachment $attachment): CustomFieldDownload
    {
        $denied = null;
        foreach ($this->attachmentRows($attachment) as $row) {
            $record = $this->target($row, FieldFormatKey::Attachment, $attachment);
            $field = $row->customField;
            if ($record === null || ! $field instanceof CustomField) {
                continue;
            }
            try {
                $this->assertVisible($actor, $record, $field);
            } catch (PermissionDeniedException $exception) {
                $denied = $exception;

                continue;
            }

            return $this->open($attachment);
        }

        if ($denied instanceof PermissionDeniedException) {
            throw $denied;
        }

        throw new DomainException('Attachment is not a custom field value.');
    }

    public function link(?User $actor, CustomValue $row): CustomFieldLinkView
    {
        $record = $this->target($row, FieldFormatKey::Link, null);
        $field = $row->customField;
        $stored = $row->value;
        if ($record === null || ! $field instanceof CustomField || ! is_string($stored) || $stored === '') {
            throw new DomainException('Custom value is not a link.');
        }
        $this->assertVisible($actor, $record, $field);

        return new CustomFieldLinkView($stored, $this->resolvedUrl($field, $stored, $record));
    }

    /**
     * @return Collection<int, CustomValue>
     */
    private function attachmentRows(Attachment $attachment): Collection
    {
        $id = $attachment->getKey();
        if (! is_numeric($id)) {
            return new Collection;
        }

        return CustomValue::query()
            ->where('value', (string) (int) $id)
            ->whereHas('customField', function (Builder $query): void {
                $query->where('field_format', FieldFormatKey::Attachment->value);
            })
            ->with(['customField.roles'])
            ->orderBy('id')
            ->get();
    }

    private function target(CustomValue $row, FieldFormatKey $expected, ?Attachment $attachment): ?Model
    {
        $field = $row->customField;
        if (! $field instanceof CustomField || (string) $field->field_format !== $expected->value) {
            return null;
        }

        $stored = $row->value;
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        if ($expected === FieldFormatKey::Attachment) {
            if (! $attachment instanceof Attachment || ! $this->containerMatches($attachment, $row)) {
                return null;
            }
            $attachmentId = $attachment->getKey();
            if (! is_numeric($attachmentId) || $stored !== (string) (int) $attachmentId) {
                return null;
            }
        }

        $recordId = $row->customized_id;
        if ($recordId <= 0) {
            return null;
        }
        $record = $this->record((string) $row->customized_type, $recordId);
        if ($record === null || ! $this->applies($record, $field)) {
            return null;
        }

        return $record;
    }

    private function record(string $type, int $id): ?Model
    {
        $record = match ($type) {
            CustomFieldTypes::ISSUE => Issue::query()->find($id),
            CustomFieldTypes::PROJECT => Project::query()->find($id),
            CustomFieldTypes::TIME_ENTRY => TimeEntry::query()->find($id),
            CustomFieldTypes::VERSION => Version::query()->find($id),
            CustomFieldTypes::USER, CustomFieldTypes::GROUP => User::query()->find($id),
            default => null,
        };
        if (! $record instanceof Model) {
            return null;
        }

        return $this->context->customizedType($record) === $type ? $record : null;
    }

    private function applies(Model $record, CustomField $field): bool
    {
        foreach ($this->scope->applicable($record) as $candidate) {
            if ((int) $candidate->id === (int) $field->id) {
                return true;
            }
        }

        return false;
    }

    private function containerMatches(Attachment $attachment, CustomValue $row): bool
    {
        $type = $attachment->container_type;
        $id = $attachment->container_id;
        if (! is_string($type) || $type === '' || ! is_numeric($id)) {
            return false;
        }

        return $type === (string) $row->customized_type && (int) $id === (int) $row->customized_id;
    }

    private function assertVisible(?User $actor, Model $record, CustomField $field): void
    {
        $this->assertCanViewHost($actor, $record);
        if (! $this->visibility->canSee($actor, $field, $this->context->project($record))) {
            throw new PermissionDeniedException(self::HIDDEN_FIELD);
        }
    }

    private function assertCanViewHost(?User $actor, Model $record): void
    {
        if ($record instanceof Issue) {
            if ($this->issues->canSee($actor, $record)) {
                return;
            }
            throw new PermissionDeniedException('view_issues');
        }

        if ($record instanceof Project) {
            if ($this->permissions->allowed($actor, 'view_project', $record)) {
                return;
            }
            throw new PermissionDeniedException('view_project');
        }

        if ($record instanceof Version) {
            $project = $record->project;
            if ($project instanceof Project && $this->permissions->allowed($actor, 'view_project', $project)) {
                return;
            }
            throw new PermissionDeniedException('view_project');
        }

        if ($record instanceof TimeEntry) {
            $project = $record->project;
            if ($project instanceof Project && $this->canSeeTimeEntry($actor, $record, $project)) {
                return;
            }
            throw new PermissionDeniedException('view_time_entries');
        }

        if ($record instanceof User) {
            if ($this->canSeeAccount($actor, $record)) {
                return;
            }
            throw new PermissionDeniedException(self::HIDDEN_FIELD);
        }

        throw new DomainException('This record does not support custom fields.');
    }

    private function canSeeTimeEntry(?User $actor, TimeEntry $entry, Project $project): bool
    {
        if (! $this->permissions->allowed($actor, 'view_time_entries', $project)) {
            return false;
        }
        if ($actor instanceof User && $this->permissions->isLoggedIn($actor)) {
            return $this->timeEntries->apply(
                TimeEntry::query()->whereKey($entry->id),
                $actor,
                $project,
            )->exists();
        }

        foreach ($this->permissions->rolesFor($actor, $project) as $role) {
            if ($role->grants('view_time_entries')
                && (string) $role->time_entries_visibility === TimeEntryVisibility::ALL) {
                return true;
            }
        }

        return false;
    }

    private function canSeeAccount(?User $actor, User $record): bool
    {
        if ($actor !== null && $actor->admin && $actor->isActive()) {
            return true;
        }
        if ((string) $record->type !== User::TYPE_USER) {
            return false;
        }

        return $actor instanceof User
            && $this->permissions->isLoggedIn($actor)
            && (int) $actor->id === (int) $record->id;
    }

    private function open(Attachment $attachment): CustomFieldDownload
    {
        $path = $this->files->absolutePath($attachment);
        if (! is_file($path)) {
            throw new DomainException('Attachment file is not stored.');
        }

        $filename = $attachment->filename;
        if ($filename === '') {
            throw new DomainException('Attachment file is not stored.');
        }

        $attachment->increment('downloads');
        $type = $attachment->content_type;

        return new CustomFieldDownload(
            $path,
            $filename,
            is_string($type) && $type !== '' ? $type : null,
        );
    }

    private function resolvedUrl(CustomField $field, string $stored, Model $record): string
    {
        $format = $this->formats->get(FieldFormatKey::Link->value);
        if (! $format instanceof LinkFormat) {
            throw new DomainException('Custom value is not a link.');
        }

        $project = $this->context->project($record);
        $recordId = $record->getKey();
        $identifier = null;
        if ($project instanceof Project && is_string($project->identifier)) {
            $identifier = $project->identifier;
        }
        $url = $format->formattedUrl(
            $field,
            $stored,
            is_numeric($recordId) ? (int) $recordId : null,
            $project instanceof Project ? (int) $project->id : null,
            $identifier,
        );
        if (is_string($url) && $url !== '') {
            return $url;
        }

        return $stored;
    }
}
