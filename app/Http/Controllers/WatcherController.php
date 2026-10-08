<?php

namespace App\Http\Controllers;

use App\Domain\Boards\MessageService;
use App\Domain\DomainException;
use App\Domain\Wiki\WikiService;
use App\Http\DomainHttp;
use App\Models\Message;
use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Watch and unwatch a wiki page or a forum topic.
 *
 * `object_type` is `wiki_page` or `message`. A reply watches its topic.
 */
class WatcherController extends Controller
{
    public function __construct(
        private readonly WikiService $wiki,
        private readonly MessageService $messages,
        private readonly DomainHttp $http,
    ) {}

    public function watch(Request $request): Response
    {
        return $this->change($request, 'watch');
    }

    public function unwatch(Request $request): Response
    {
        return $this->change($request, 'unwatch');
    }

    public function store(Request $request): Response
    {
        return $this->change($request, 'add');
    }

    public function destroy(Request $request): Response
    {
        return $this->change($request, 'remove');
    }

    private function change(Request $request, string $action): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        $type = $this->http->text($request, 'object_type');
        $id = $this->http->nullableInt($request, 'object_id');
        if ($id === null) {
            abort(404);
        }
        $target = $actor;
        if ($action === 'add' || $action === 'remove') {
            $userId = $this->http->nullableInt($request, 'user_id');
            $found = $userId === null ? null : User::query()->find($userId);
            if (! $found instanceof User) {
                abort(404);
            }
            $target = $found;
        }
        try {
            if ($type === 'wiki_page') {
                $this->wikiPage($actor, $target, $id, $action);
            } elseif ($type === 'message') {
                $this->forumMessage($actor, $target, $id, $action);
            } else {
                abort(404);
            }
        } catch (DomainException $exception) {
            return $this->http->fail($request, $exception);
        }

        return back();
    }

    private function wikiPage(User $actor, User $target, int $id, string $action): void
    {
        $page = WikiPage::query()->find($id);
        if (! $page instanceof WikiPage) {
            abort(404);
        }
        match ($action) {
            'watch' => $this->wiki->watch($actor, $page),
            'unwatch' => $this->wiki->unwatch($actor, $page),
            'add' => $this->wiki->addWatcher($actor, $page, $target),
            'remove' => $this->wiki->removeWatcher($actor, $page, $target),
            default => abort(404),
        };
    }

    private function forumMessage(User $actor, User $target, int $id, string $action): void
    {
        $message = Message::query()->find($id);
        if (! $message instanceof Message) {
            abort(404);
        }
        match ($action) {
            'watch' => $this->messages->watch($actor, $message),
            'unwatch' => $this->messages->unwatch($actor, $message),
            'add' => $this->messages->addWatcher($actor, $message, $target),
            'remove' => $this->messages->removeWatcher($actor, $message, $target),
            default => abort(404),
        };
    }
}
