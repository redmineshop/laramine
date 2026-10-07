<?php

namespace App\Http\Controllers;

use App\Domain\Documents\DocumentService;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal document pages. These are not a Redmine screen.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('viewDocuments', $project);
        $actor = $this->actor($request);
        $sortBy = $request->query('sort_by');
        $sort = is_string($sortBy) ? $sortBy : 'category';

        return Inertia::render('Documents/Index', [
            'projectId' => (int) $project->id,
            'sortBy' => $sort,
            'groups' => $this->documents->grouped($actor, $project, $sort),
            'categories' => $this->documents->categoryOptions($project),
            'canAdd' => $actor instanceof User && $actor->can('addDocuments', $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('addDocuments', $project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        $category = $request->input('category_id');
        $categoryId = is_numeric($category) ? (int) $category : 0;

        try {
            $this->documents->create(
                $actor,
                $project,
                $this->text($request, 'title'),
                $categoryId,
                $this->nullableText($request, 'description'),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return redirect()->route('projects.documents.index', ['project' => $project->id]);
    }

    public function update(Request $request, Project $project, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }
        if ((int) $document->project_id !== (int) $project->id) {
            abort(404);
        }

        $category = $request->input('category_id');
        $categoryId = is_numeric($category) ? (int) $category : 0;

        try {
            $this->documents->update(
                $actor,
                $document,
                $this->text($request, 'title'),
                $categoryId,
                $this->nullableText($request, 'description'),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return redirect()->route('projects.documents.index', ['project' => $project->id]);
    }

    public function destroy(Request $request, Project $project, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }
        if ((int) $document->project_id !== (int) $project->id) {
            abort(404);
        }

        try {
            $this->documents->delete($actor, $document);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return redirect()->route('projects.documents.index', ['project' => $project->id]);
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    private function nullableText(Request $request, string $key): ?string
    {
        $value = $request->input($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
