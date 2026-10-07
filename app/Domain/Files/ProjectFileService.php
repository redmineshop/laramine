<?php

namespace App\Domain\Files;

use App\Domain\Acl\PermissionService;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use DateTimeInterface;

/**
 * Files attached to a project or to a version that project owns.
 *
 * `view_files` lists them. Upload, delete, download, and thumbnails go
 * through `AttachmentContainerService`. Versions shared into the project
 * from somewhere else are not containers here. The project container is
 * first. Versions follow `VersionOrder::forFiles`. Files inside a container
 * follow `AttachmentSort`.
 */
final class ProjectFileService
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * @return list<array{container_type: string, container_id: int, attachment_ids: list<int>}>
     */
    public function grouped(?User $actor, Project $project, string $sortBy): array
    {
        if (! $this->permissions->allowed($actor, 'view_files', $project)) {
            throw new PermissionDeniedException('view_files');
        }

        $sort = $this->sort($sortBy);
        $containers = [[
            'container_type' => 'Project',
            'container_id' => (int) $project->id,
            'attachment_ids' => $this->sortedIds('Project', (int) $project->id, $sort),
        ]];

        $versions = [];
        foreach (Version::query()->where('project_id', (int) $project->id)->get() as $version) {
            $versions[] = $version;
        }
        usort($versions, function (Version $left, Version $right): int {
            return VersionOrder::forFiles(
                $this->date($left),
                (int) $left->id,
                (string) $left->name,
                $this->date($right),
                (int) $right->id,
                (string) $right->name,
            );
        });
        foreach ($versions as $version) {
            $containers[] = [
                'container_type' => 'Version',
                'container_id' => (int) $version->id,
                'attachment_ids' => $this->sortedIds('Version', (int) $version->id, $sort),
            ];
        }

        return $containers;
    }

    public function contains(Project $project, Attachment $attachment): bool
    {
        $type = (string) $attachment->container_type;
        $id = (int) $attachment->container_id;
        if ($type === 'Project' && $id === (int) $project->id) {
            return true;
        }
        if ($type !== 'Version') {
            return false;
        }

        return Version::query()
            ->where('id', $id)
            ->where('project_id', (int) $project->id)
            ->exists();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function versionOptions(Project $project): array
    {
        $options = [];
        $versions = Version::query()
            ->where('project_id', (int) $project->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        foreach ($versions as $version) {
            $options[] = [
                'id' => (int) $version->id,
                'name' => (string) $version->name,
            ];
        }

        return $options;
    }

    /**
     * @return list<int>
     */
    private function sortedIds(string $type, int $id, string $sort): array
    {
        $rows = [];
        $attachments = Attachment::query()
            ->where('container_type', $type)
            ->where('container_id', $id)
            ->get();
        foreach ($attachments as $attachment) {
            $rows[] = [
                'id' => (int) $attachment->id,
                'filename' => (string) $attachment->filename,
                'created_on' => $this->stamp($attachment->created_on),
                'filesize' => (int) $attachment->filesize,
                'downloads' => (int) $attachment->downloads,
            ];
        }

        return AttachmentSort::ids($rows, $sort);
    }

    private function sort(string $sortBy): string
    {
        if (in_array($sortBy, ['filename', 'created_on', 'size', 'downloads'], true)) {
            return $sortBy;
        }

        return 'filename';
    }

    private function date(Version $version): ?string
    {
        $value = $version->getRawOriginal('effective_date');
        if (! is_string($value) || $value === '') {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function stamp(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return '1970-01-01 00:00:00';
    }
}
