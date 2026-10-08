<?php

namespace App\Http\Controllers;

use App\Domain\Boards\BoardService;
use App\Domain\Boards\MessageList;
use App\Domain\Boards\MessageService;
use App\Domain\DomainException;
use App\Http\DomainHttp;
use App\Http\ModulePermission;
use App\Http\ProjectLocator;
use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forum boards. These screens are not a Redmine layout.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly BoardService $boards,
        private readonly MessageService $messages,
        private readonly MessageList $lists,
        private readonly ProjectLocator $projects,
        private readonly ModulePermission $permissions,
        private readonly DomainHttp $http,
    ) {}

    public function index(Request $request, string $project): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        try {
            $rows = $this->boards->index($actor, $found);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return Inertia::render('Boards/Index', [
            'project' => (string) $found->identifier,
            'boards' => $this->withDepth($rows),
            'canManage' => $actor instanceof User && $this->permissions->allows($actor, $found, 'boards', 'manage_boards'),
        ]);
    }

    public function create(Request $request, string $project): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $found, 'boards', 'manage_boards')) {
            return $this->http->denied($request);
        }

        return Inertia::render('Boards/Edit', [
            'mode' => 'new',
            'project' => (string) $found->identifier,
            'board' => null,
            'boards' => $this->boards->index($actor, $found),
        ]);
    }

    public function store(Request $request, string $project): Response
    {
        $found = $this->projects->find($project);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $board = $this->boards->create(
                $actor,
                $found,
                $this->http->text($request, 'name'),
                $this->optional($request, 'description'),
                $this->http->nullableInt($request, 'parent_id'),
                $this->http->nullableInt($request, 'position'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/boards/'.$board->id);
    }

    public function show(Request $request, string $project, int $board): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $row = $this->board($board, $found);
        $actor = $this->actor($request);
        try {
            $topics = $this->messages->topics($actor, $row);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $page = $this->lists->topics($topics, $this->http->queryString($request, 'sort'), $this->http->queryInt($request, 'page'), $this->http->queryInt($request, 'per_page'));

        return Inertia::render('Boards/Show', [
            'project' => (string) $found->identifier,
            'board' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => is_string($row->description) ? $row->description : null,
                'topics_count' => (int) $row->topics_count,
                'messages_count' => (int) $row->messages_count,
            ],
            'topics' => $page['rows'],
            'page' => $page['page'],
            'pages' => $page['pages'],
            'perPage' => $page['per_page'],
            'total' => $page['total'],
            'sort' => $this->http->queryString($request, 'sort') ?? '',
            'canManage' => $actor instanceof User && $this->permissions->allows($actor, $found, 'boards', 'manage_boards'),
            'canPost' => $actor instanceof User && $this->permissions->allows($actor, $found, 'boards', 'add_messages'),
        ]);
    }

    public function edit(Request $request, string $project, int $board): InertiaResponse|Response
    {
        $found = $this->projects->find($project);
        $row = $this->board($board, $found);
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $found, 'boards', 'manage_boards')) {
            return $this->http->denied($request);
        }

        return Inertia::render('Boards/Edit', [
            'mode' => 'edit',
            'project' => (string) $found->identifier,
            'board' => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'description' => is_string($row->description) ? $row->description : '',
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'position' => $row->position === null ? null : (int) $row->position,
            ],
            'boards' => $this->boards->index($actor, $found),
        ]);
    }

    public function update(Request $request, string $project, int $board): Response
    {
        $found = $this->projects->find($project);
        $row = $this->board($board, $found);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->boards->update(
                $actor,
                $row,
                $this->http->text($request, 'name'),
                $this->optional($request, 'description'),
                $this->http->nullableInt($request, 'parent_id'),
                $this->http->nullableInt($request, 'position'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/boards/'.$row->id);
    }

    public function destroy(Request $request, string $project, int $board): Response
    {
        $found = $this->projects->find($project);
        $row = $this->board($board, $found);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->boards->delete($actor, $row);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect('/projects/'.$found->identifier.'/boards');
    }

    private function board(int $id, Project $project): Board
    {
        $board = Board::query()->find($id);
        if (! $board instanceof Board || (int) $board->project_id !== (int) $project->id) {
            abort(404);
        }

        return $board;
    }

    /**
     * @param  list<array{id: int, name: string, description: string|null, parent_id: int|null, position: int|null, topics_count: int, messages_count: int, last_message_id: int|null}>  $rows
     * @return list<array{id: int, name: string, description: string|null, parent_id: int|null, position: int|null, topics_count: int, messages_count: int, last_message_id: int|null, depth: int}>
     */
    private function withDepth(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = $row;
        }
        $shaped = [];
        foreach ($rows as $row) {
            $depth = 0;
            $parent = $row['parent_id'];
            $seen = [];
            while ($parent !== null && isset($byId[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $depth++;
                $parent = $byId[$parent]['parent_id'];
            }
            $row['depth'] = $depth;
            $shaped[] = $row;
        }

        return $shaped;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function optional(Request $request, string $key): ?string
    {
        if (! $request->exists($key)) {
            return null;
        }
        $value = $request->input($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
