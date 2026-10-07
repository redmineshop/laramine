<?php

namespace App\Http\Controllers;

use App\Domain\Attachments\AttachmentService;
use App\Domain\DomainException;
use App\Domain\Files\ProjectFileService;
use App\Domain\PermissionDeniedException;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Minimal project file pages. These are not a Redmine screen.
 */
class ProjectFileController extends Controller
{
    public function __construct(
        private readonly ProjectFileService $files,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('viewFiles', $project);
        $actor = $this->actor($request);
        $sortBy = $request->query('sort_by');
        $sort = is_string($sortBy) ? $sortBy : 'filename';

        return Inertia::render('Files/Index', [
            'projectId' => (int) $project->id,
            'sortBy' => $sort,
            'containers' => $this->present($this->files->grouped($actor, $project, $sort)),
            'versions' => $this->files->versionOptions($project),
            'canManage' => $actor instanceof User && $actor->can('manageFiles', $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manageFiles', $project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        $upload = $request->file('file');
        if (! $upload instanceof UploadedFile) {
            return back()->withErrors(['file' => 'File is required.']);
        }
        $path = $upload->getRealPath();
        if (! is_string($path)) {
            return back()->withErrors(['file' => 'File is required.']);
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            return back()->withErrors(['file' => 'File is required.']);
        }

        $version = $request->input('version_id');
        $versionId = is_numeric($version) && (int) $version > 0 ? (int) $version : null;

        try {
            $this->files->attach(
                $actor,
                $project,
                $versionId,
                $upload->getClientOriginalName(),
                $contents,
                $upload->getClientMimeType(),
                null,
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()->route('projects.files.index', ['project' => $project->id]);
    }

    public function destroy(Request $request, Project $project, Attachment $attachment): RedirectResponse
    {
        $this->authorize('manageFiles', $project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->files->delete($actor, $project, $attachment);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()->route('projects.files.index', ['project' => $project->id]);
    }

    public function download(Request $request, Project $project, Attachment $attachment): BinaryFileResponse
    {
        $this->authorize('viewFiles', $project);
        $actor = $this->actor($request);

        try {
            $recorded = $this->files->recordDownload($actor, $project, $attachment);
            $path = $this->attachments->absolutePath($recorded);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException) {
            abort(404);
        }

        if (! is_file($path)) {
            abort(404);
        }

        $type = $recorded->content_type;
        $headers = [];
        if (is_string($type) && $type !== '') {
            $headers['Content-Type'] = $type;
        }

        return response()->download($path, (string) $recorded->filename, $headers);
    }

    /**
     * @param  list<array{container_type: string, container_id: int, attachment_ids: list<int>}>  $containers
     * @return list<array{container_type: string, container_id: int, files: list<array{id: int, filename: string, filesize: int, downloads: int}>}>
     */
    private function present(array $containers): array
    {
        $presented = [];
        foreach ($containers as $container) {
            $files = [];
            if ($container['attachment_ids'] !== []) {
                $byId = [];
                foreach (Attachment::query()->whereIn('id', $container['attachment_ids'])->get() as $attachment) {
                    $byId[(int) $attachment->id] = [
                        'id' => (int) $attachment->id,
                        'filename' => (string) $attachment->filename,
                        'filesize' => (int) $attachment->filesize,
                        'downloads' => (int) $attachment->downloads,
                    ];
                }
                foreach ($container['attachment_ids'] as $id) {
                    if (isset($byId[$id])) {
                        $files[] = $byId[$id];
                    }
                }
            }
            $presented[] = [
                'container_type' => $container['container_type'],
                'container_id' => $container['container_id'],
                'files' => $files,
            ];
        }

        return $presented;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
