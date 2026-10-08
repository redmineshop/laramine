<?php

namespace App\Http\Controllers;

use App\Domain\Boards\MessageList;
use App\Domain\Boards\MessageService;
use App\Domain\DomainException;
use App\Http\DomainHttp;
use App\Http\ModulePermission;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forum topics and replies. These screens are not a Redmine layout.
 */
class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly MessageList $lists,
        private readonly ModulePermission $permissions,
        private readonly DomainHttp $http,
    ) {}

    public function create(Request $request, int $board): InertiaResponse|Response
    {
        $row = $this->board($board);
        $project = $this->project($row);
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->permissions->allows($actor, $project, 'boards', 'add_messages')) {
            return $this->http->denied($request);
        }

        return Inertia::render('Messages/Edit', $this->form($actor, $project, $row, null, 'new', '', '', false, false, null));
    }

    public function store(Request $request, int $board): Response
    {
        $row = $this->board($board);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $topic = $this->messages->postTopic(
                $actor,
                $row,
                $this->http->text($request, 'subject'),
                $this->optional($request, 'content'),
                $this->sticky($request),
                $this->http->checked($request, 'locked'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->topicPath($row, $topic));
    }

    public function show(Request $request, int $board, int $message): InertiaResponse|Response
    {
        $row = $this->board($board);
        $topic = $this->message($row, $message);
        if (! $topic->isTopic()) {
            return redirect('/boards/'.$row->id.'/topics/'.(int) $topic->parent_id.'#message-'.$topic->id);
        }
        $project = $this->project($row);
        $actor = $this->actor($request);
        try {
            $replies = $this->messages->replies($actor, $topic);
            $html = $this->messages->html($actor, $topic);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $page = $this->lists->slice($replies, $this->http->queryInt($request, 'page'), $this->http->queryInt($request, 'per_page'));
        $visible = [];
        foreach ($page['rows'] as $reply) {
            $model = Message::query()->find($reply['id']);
            if (! $model instanceof Message) {
                continue;
            }
            $reply['content_html'] = $this->messages->html($actor, $model);
            $reply['canEdit'] = $actor instanceof User && $this->messages->canEdit($actor, $model);
            $reply['canDelete'] = $actor instanceof User && $this->messages->canDelete($actor, $model);
            $visible[] = $reply;
        }
        $watching = false;
        $watcherIds = null;
        if ($actor instanceof User) {
            $watching = $this->messages->isWatching($actor, $topic);
            if ($this->permissions->allows($actor, $project, 'boards', 'view_message_watchers')) {
                $watcherIds = $this->messages->watcherIds($actor, $topic);
            }
        }

        return Inertia::render('Messages/Show', [
            'project' => (string) $project->identifier,
            'board' => ['id' => (int) $row->id, 'name' => (string) $row->name],
            'topic' => [
                'id' => (int) $topic->id,
                'subject' => (string) $topic->subject,
                'author_id' => is_numeric($topic->author_id) ? (int) $topic->author_id : null,
                'content' => is_string($topic->content) ? $topic->content : '',
                'content_html' => $html,
                'sticky' => (int) $topic->sticky,
                'locked' => (bool) $topic->locked,
                'replies_count' => (int) $topic->replies_count,
            ],
            'replies' => $visible,
            'page' => $page['page'],
            'pages' => $page['pages'],
            'perPage' => $page['per_page'],
            'total' => $page['total'],
            'attachments' => $this->attachmentRows((int) $topic->id),
            'watching' => $watching,
            'watcherIds' => $watcherIds,
            'canReply' => $actor instanceof User && $this->messages->canReply($actor, $topic),
            'canEdit' => $actor instanceof User && $this->messages->canEdit($actor, $topic),
            'canDelete' => $actor instanceof User && $this->messages->canDelete($actor, $topic),
            'canModerate' => $actor instanceof User && $this->permissions->allows($actor, $project, 'boards', 'edit_messages'),
        ]);
    }

    public function edit(Request $request, int $board, int $message): InertiaResponse|Response
    {
        $row = $this->board($board);
        $model = $this->message($row, $message);
        $project = $this->project($row);
        $actor = $this->actor($request);
        if (! $actor instanceof User || ! $this->messages->canEdit($actor, $model)) {
            return $this->http->denied($request);
        }

        return Inertia::render('Messages/Edit', $this->form(
            $actor,
            $project,
            $row,
            $model,
            'edit',
            (string) $model->subject,
            is_string($model->content) ? $model->content : '',
            (bool) $model->locked,
            (int) $model->sticky === 1,
            null,
        ));
    }

    public function update(Request $request, int $board, int $message): Response
    {
        $row = $this->board($board);
        $model = $this->message($row, $message);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $sticky = $request->exists('sticky') ? $this->sticky($request) : null;
        $locked = $request->exists('locked') ? $this->http->checked($request, 'locked') : null;
        try {
            $saved = $this->messages->update(
                $actor,
                $model,
                $this->http->text($request, 'subject'),
                $this->optional($request, 'content'),
                $sticky,
                $locked,
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $target = $saved->isTopic() ? $saved : $this->root($saved);

        return redirect($this->topicPath($row, $target));
    }

    public function reply(Request $request, int $board, int $message): Response
    {
        $row = $this->board($board);
        $topic = $this->message($row, $message);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->messages->reply($actor, $topic, $this->optional($request, 'subject'), $this->optional($request, 'content'));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return redirect($this->topicPath($row, $topic->isTopic() ? $topic : $this->root($topic)));
    }

    public function destroy(Request $request, int $board, int $message): Response
    {
        $row = $this->board($board);
        $model = $this->message($row, $message);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $topic = $model->isTopic() ? null : $this->root($model);
        try {
            $this->messages->delete($actor, $model);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        if ($topic instanceof Message) {
            return redirect($this->topicPath($row, $topic));
        }
        $project = $this->project($row);

        return redirect('/projects/'.$project->identifier.'/boards/'.$row->id);
    }

    public function quote(Request $request, int $board, int $message): InertiaResponse|Response
    {
        $row = $this->board($board);
        $model = $this->message($row, $message);
        $project = $this->project($row);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $quoted = $this->messages->quote($actor, $model);
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $root = $model->isTopic() ? $model : $this->root($model);

        return Inertia::render('Messages/Edit', $this->form($actor, $project, $row, $root, 'quote', '', $quoted, false, false, (int) $model->id));
    }

    public function preview(Request $request, int $board, ?int $message = null): Response
    {
        $row = $this->board($board);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        if ($message !== null) {
            $this->message($row, $message);
        }
        try {
            $html = $this->messages->preview($actor, $row, $this->http->text($request, 'content'));
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function attach(Request $request, int $board, int $message): Response
    {
        $row = $this->board($board);
        $model = $this->message($row, $message);
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        try {
            $this->messages->attach(
                $actor,
                $model,
                $this->http->text($request, 'token'),
                $this->optional($request, 'filename'),
                $this->optional($request, 'description'),
            );
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }
        $target = $model->isTopic() ? $model : $this->root($model);

        return redirect($this->topicPath($row, $target));
    }

    /**
     * @return array<string, mixed>
     */
    private function form(
        User $actor,
        Project $project,
        Board $board,
        ?Message $message,
        string $mode,
        string $subject,
        string $content,
        bool $locked,
        bool $sticky,
        ?int $quotedId,
    ): array {
        return [
            'mode' => $mode,
            'project' => (string) $project->identifier,
            'boardId' => (int) $board->id,
            'messageId' => $message === null ? null : (int) $message->id,
            'subject' => $subject,
            'content' => $content,
            'locked' => $locked,
            'sticky' => $sticky,
            'quotedId' => $quotedId,
            'canModerate' => $this->permissions->allows($actor, $project, 'boards', 'edit_messages'),
        ];
    }

    private function board(int $id): Board
    {
        $board = Board::query()->find($id);
        if (! $board instanceof Board) {
            abort(404);
        }

        return $board;
    }

    private function project(Board $board): Project
    {
        $project = Project::query()->find($board->project_id);
        if (! $project instanceof Project) {
            abort(404);
        }

        return $project;
    }

    private function message(Board $board, int $id): Message
    {
        $message = Message::query()->find($id);
        if (! $message instanceof Message || (int) $message->board_id !== (int) $board->id) {
            abort(404);
        }

        return $message;
    }

    private function root(Message $message): Message
    {
        $parent = Message::query()->find($message->parent_id);
        if (! $parent instanceof Message) {
            abort(404);
        }

        return $parent;
    }

    private function topicPath(Board $board, Message $topic): string
    {
        return '/boards/'.$board->id.'/topics/'.$topic->id;
    }

    /**
     * @return list<array{id: int, filename: string}>
     */
    private function attachmentRows(int $id): array
    {
        $rows = [];
        foreach (Attachment::query()->where('container_type', 'Message')->where('container_id', $id)->orderBy('id')->get() as $attachment) {
            $rows[] = [
                'id' => (int) $attachment->id,
                'filename' => (string) $attachment->filename,
            ];
        }

        return $rows;
    }

    private function sticky(Request $request): int
    {
        return $this->http->checked($request, 'sticky') ? 1 : 0;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    private function optional(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : null;
    }
}
