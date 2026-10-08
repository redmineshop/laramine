<?php

namespace App\Domain\Issues\History;

use App\Domain\Attachments\AttachmentThumbnails;
use App\Domain\Attachments\ThumbnailBinaries;
use App\Domain\Settings\SettingValue;
use App\Models\Attachment;
use App\Models\Issue;

/**
 * Attachment rows shown on an issue and on its journals.
 *
 * Files are ordered by `created_on`, then `id`. A journal counts as thumbnail
 * content when at least one of those files is thumbnailable.
 */
final class JournalAttachmentList
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly AttachmentThumbnails $thumbnails,
        private readonly ThumbnailBinaries $binaries,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return array<int, list<JournalAttachmentView>>
     */
    public function grouped(string $containerType, array $ids): array
    {
        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }
        if ($ids === []) {
            return $grouped;
        }

        $enabled = $this->settings->thumbnailsEnabled();
        $size = $this->settings->thumbnailsSize();
        $rows = Attachment::query()
            ->where('container_type', $containerType)
            ->whereIn('container_id', $ids)
            ->orderBy('created_on')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $containerId = (int) $row->container_id;
            if (! array_key_exists($containerId, $grouped)) {
                continue;
            }
            $grouped[$containerId][] = $this->view($row, $enabled, $size);
        }

        return $grouped;
    }

    /**
     * @return list<JournalAttachmentView>
     */
    public function forIssue(Issue $issue): array
    {
        $id = (int) $issue->id;
        $grouped = $this->grouped('Issue', [$id]);

        return $grouped[$id] ?? [];
    }

    public function downloadAllItem(string $containerType, int $containerId, int $count): ?JournalMenuItemView
    {
        if ($count < 2) {
            return null;
        }

        return new JournalMenuItemView(
            'download_all',
            JournalActionList::DOWNLOAD_ALL,
            null,
            $containerType,
            $containerId,
        );
    }

    private function view(Attachment $row, bool $enabled, int $size): JournalAttachmentView
    {
        $filename = (string) $row->filename;
        $thumbnailable = $enabled && $this->thumbnails->canThumbnail(
            $filename,
            $this->binaries->convertAvailable(),
            $this->binaries->gsAvailable(),
        );
        $contentType = $row->content_type;
        $description = $row->description;

        return new JournalAttachmentView(
            (int) $row->id,
            $filename,
            is_string($contentType) && $contentType !== '' ? $contentType : null,
            is_string($description) && $description !== '' ? $description : null,
            (int) $row->filesize,
            $thumbnailable,
            $thumbnailable ? $size : null,
        );
    }
}
