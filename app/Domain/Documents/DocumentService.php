<?php

namespace App\Domain\Documents;

use App\Domain\Acl\PermissionService;
use App\Domain\Attachments\AttachmentService;
use App\Domain\Attachments\AttachmentThumbnailRenderer;
use App\Domain\DomainException;
use App\Domain\Notifications\ModuleNotifier;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\CustomValue;
use App\Models\Document;
use App\Models\Enumeration;
use App\Models\Project;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Documents, their categories, and their attachments.
 *
 * `view_documents` lists and reads. `add_documents` creates. `edit_documents`
 * updates. `delete_documents` deletes the row, its attachments, and its
 * custom values. A file is claimed through `AttachmentContainerService`.
 * The category is a
 * `DocumentCategory` enumeration shared or owned by the project. A new
 * category must be active. Grouping is `DocumentGroups`.
 */
final class DocumentService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AttachmentService $attachments,
        private readonly AttachmentThumbnailRenderer $thumbnails,
        private readonly ModuleNotifier $notifications,
    ) {}

    public function create(
        User $actor,
        Project $project,
        string $title,
        int $categoryId,
        ?string $description,
    ): Document {
        if (! $this->permissions->allowed($actor, 'add_documents', $project)) {
            throw new PermissionDeniedException('add_documents');
        }

        $this->category($project, $categoryId, null);
        $document = new Document([
            'category_id' => $categoryId,
            'created_on' => now(),
            'description' => $this->description($description),
            'project_id' => (int) $project->id,
            'title' => $this->title($title),
        ]);
        $document->save();
        $fresh = $document->refresh();
        $fresh->load('project');
        $this->notifications->documentAdded($actor, $fresh);

        return $fresh;
    }

    public function update(
        User $actor,
        Document $document,
        string $title,
        int $categoryId,
        ?string $description,
    ): Document {
        $project = $this->project($document);
        if (! $this->permissions->allowed($actor, 'edit_documents', $project)) {
            throw new PermissionDeniedException('edit_documents');
        }

        $this->category($project, $categoryId, (int) $document->category_id);
        $document->title = $this->title($title);
        $document->category_id = $categoryId;
        $document->description = $this->description($description);
        $document->save();

        return $document->refresh();
    }

    public function delete(User $actor, Document $document): void
    {
        $project = $this->project($document);
        if (! $this->permissions->allowed($actor, 'delete_documents', $project)) {
            throw new PermissionDeniedException('delete_documents');
        }

        DB::transaction(function () use ($document): void {
            $files = Attachment::query()
                ->where('container_type', 'Document')
                ->where('container_id', (int) $document->id)
                ->get();
            foreach ($files as $file) {
                $this->thumbnails->forget($file);
                $this->attachments->forgetFile($file);
                $file->delete();
            }
            CustomValue::query()
                ->where('customized_type', 'Document')
                ->where('customized_id', (int) $document->id)
                ->delete();
            $document->delete();
        });
    }

    /**
     * @return list<array{key: string, document_ids: list<int>}>
     */
    public function grouped(?User $actor, Project $project, string $sortBy): array
    {
        if (! $this->permissions->allowed($actor, 'view_documents', $project)) {
            throw new PermissionDeniedException('view_documents');
        }

        $rows = [];
        $documents = Document::query()
            ->where('project_id', (int) $project->id)
            ->orderBy('id')
            ->get();
        foreach ($documents as $document) {
            $rows[] = $this->presentation($document);
        }

        return DocumentGroups::group($rows, $sortBy);
    }

    /**
     * Active categories the project may assign, position then id.
     *
     * @return list<array{id: int, name: string}>
     */
    public function categoryOptions(Project $project): array
    {
        $options = [];
        $categories = Enumeration::query()
            ->where('type', 'DocumentCategory')
            ->where('active', true)
            ->where(function (Builder $query) use ($project): void {
                $query->whereNull('project_id')->orWhere('project_id', (int) $project->id);
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        foreach ($categories as $category) {
            $options[] = [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ];
        }

        return $options;
    }

    /**
     * @return array{id: int, title: string, category_id: int, created_on: string, updated_on: string, author_id: int|null}
     */
    private function presentation(Document $document): array
    {
        $last = $this->lastAttachment($document);
        $created = $this->stamp($document->created_on);
        $updated = $last === null ? $created : $this->stamp($last->created_on);

        return [
            'id' => (int) $document->id,
            'title' => (string) $document->title,
            'category_id' => (int) $document->category_id,
            'created_on' => $created,
            'updated_on' => $updated,
            'author_id' => $last === null ? null : (int) $last->author_id,
        ];
    }

    private function lastAttachment(Document $document): ?Attachment
    {
        $last = null;
        $files = Attachment::query()
            ->where('container_type', 'Document')
            ->where('container_id', (int) $document->id)
            ->get();
        foreach ($files as $file) {
            if ($last === null || $this->later($file, $last)) {
                $last = $file;
            }
        }

        return $last;
    }

    private function later(Attachment $candidate, Attachment $current): bool
    {
        $left = $this->stamp($candidate->created_on);
        $right = $this->stamp($current->created_on);
        if ($left === $right) {
            return (int) $candidate->id > (int) $current->id;
        }

        return $left > $right;
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

    private function category(Project $project, int $categoryId, ?int $currentId): void
    {
        $category = Enumeration::query()->find($categoryId);
        if (! $category instanceof Enumeration || (string) $category->type !== 'DocumentCategory') {
            throw new DomainException('Category is not a document category.');
        }
        $owner = $category->project_id;
        if ($owner !== null && (int) $owner !== (int) $project->id) {
            throw new DomainException('Category is not available on this project.');
        }
        $unchanged = $currentId !== null && $currentId === $categoryId;
        if (! $unchanged && ! $category->active) {
            throw new DomainException('Category is not active.');
        }
    }

    private function project(Document $document): Project
    {
        $project = $document->project;
        if (! $project instanceof Project) {
            throw new DomainException('Document has no project.');
        }

        return $project;
    }

    private function title(string $title): string
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            throw new DomainException('Title is required.');
        }
        if (mb_strlen($trimmed) > 255) {
            throw new DomainException('Title is too long.');
        }

        return $trimmed;
    }

    private function description(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }

        return $description;
    }
}
