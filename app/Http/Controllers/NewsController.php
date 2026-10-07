<?php

namespace App\Http\Controllers;

use App\Domain\DomainException;
use App\Domain\News\NewsService;
use App\Domain\PermissionDeniedException;
use App\Models\Comment;
use App\Models\News;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal news pages. These are not a Redmine screen.
 */
class NewsController extends Controller
{
    public function __construct(private readonly NewsService $news) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        return Inertia::render('News/Index', [
            'scope' => 'all',
            'projectId' => null,
            'news' => $this->rows($this->news->visibleIds($actor, null)),
            'canManage' => false,
        ]);
    }

    public function projectIndex(Request $request, Project $project): Response
    {
        $this->authorize('viewNews', $project);
        $actor = $this->actor($request);

        return Inertia::render('News/Index', [
            'scope' => 'project',
            'projectId' => (int) $project->id,
            'news' => $this->rows($this->news->visibleIds($actor, $project)),
            'canManage' => $actor instanceof User && $actor->can('manageNews', $project),
        ]);
    }

    public function show(Request $request, News $news): Response
    {
        $this->authorize('view', $news);
        $actor = $this->actor($request);
        $project = $news->project;
        $watcherIds = $this->news->watcherIds($actor, $news);
        $watching = $actor instanceof User && in_array((int) $actor->id, $watcherIds, true);

        return Inertia::render('News/Show', [
            'news' => [
                'id' => (int) $news->id,
                'project_id' => (int) $news->project_id,
                'title' => (string) $news->title,
                'summary' => (string) $news->summary,
                'description' => $news->description === null ? '' : (string) $news->description,
                'author_id' => (int) $news->author_id,
                'comments_count' => (int) $news->comments_count,
            ],
            'comments' => $this->news->comments($actor, $news),
            'watcherIds' => $watcherIds,
            'watching' => $watching,
            'canComment' => $actor instanceof User && $actor->can('comment', $news),
            'canManage' => $project instanceof Project && ($actor instanceof User && $actor->can('manageNews', $project)),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manageNews', $project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $created = $this->news->create(
                $actor,
                $project,
                $this->text($request, 'title'),
                $this->nullableText($request, 'summary'),
                $this->nullableText($request, 'description'),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['news' => $exception->getMessage()]);
        }

        return redirect()->route('news.show', ['news' => $created->id]);
    }

    public function update(Request $request, News $news): RedirectResponse
    {
        $this->authorize('update', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->news->update(
                $actor,
                $news,
                $this->text($request, 'title'),
                $this->nullableText($request, 'summary'),
                $this->nullableText($request, 'description'),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['news' => $exception->getMessage()]);
        }

        return redirect()->route('news.show', ['news' => $news->id]);
    }

    public function destroy(Request $request, News $news): RedirectResponse
    {
        $this->authorize('delete', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }
        $projectId = (int) $news->project_id;

        try {
            $this->news->delete($actor, $news);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['news' => $exception->getMessage()]);
        }

        return redirect()->route('projects.news.index', ['project' => $projectId]);
    }

    public function storeComment(Request $request, News $news): RedirectResponse
    {
        $this->authorize('comment', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->news->addComment($actor, $news, $this->text($request, 'content'));
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['news' => $exception->getMessage()]);
        }

        return redirect()->route('news.show', ['news' => $news->id]);
    }

    public function destroyComment(Request $request, News $news, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->news->deleteComment($actor, $news, $comment);
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (DomainException $exception) {
            return back()->withErrors(['news' => $exception->getMessage()]);
        }

        return redirect()->route('news.show', ['news' => $news->id]);
    }

    public function watch(Request $request, News $news): RedirectResponse
    {
        $this->authorize('view', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->news->watch($actor, $news);
        } catch (PermissionDeniedException) {
            abort(403);
        }

        return redirect()->route('news.show', ['news' => $news->id]);
    }

    public function unwatch(Request $request, News $news): RedirectResponse
    {
        $this->authorize('view', $news);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->news->unwatch($actor, $news);
        } catch (PermissionDeniedException) {
            abort(403);
        }

        return redirect()->route('news.show', ['news' => $news->id]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, project_id: int, title: string, summary: string, author_id: int, comments_count: int}>
     */
    private function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach (News::query()->whereIn('id', $ids)->get() as $news) {
            $byId[(int) $news->id] = [
                'id' => (int) $news->id,
                'project_id' => (int) $news->project_id,
                'title' => (string) $news->title,
                'summary' => (string) $news->summary,
                'author_id' => (int) $news->author_id,
                'comments_count' => (int) $news->comments_count,
            ];
        }

        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }

        return $rows;
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
