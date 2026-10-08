<?php

namespace App\Http\Controllers;

use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Reactions\ReactionService;
use App\Http\DomainHttp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds and removes the signed-in user's thumbs-up on one record.
 *
 * Checks run in pin order: sign-in, the `reactions_enabled` setting, the
 * `object_type` name, the record, then visibility and an active project. Only
 * a script request (XHR or JSON) gets a body: the refreshed control state. A
 * plain browser request that passes every check is 404, as the pin answers
 * only its JavaScript format.
 */
class ReactionController extends Controller
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly DomainHttp $http,
    ) {}

    public function store(Request $request): Response
    {
        return $this->change($request, null);
    }

    public function destroy(Request $request, int $reaction): Response
    {
        return $this->change($request, $reaction);
    }

    private function change(Request $request, ?int $reactionId): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->http->denied($request);
        }
        if (! $this->reactions->enabled()) {
            abort(403);
        }

        $type = $this->http->text($request, 'object_type');
        $id = $this->http->nullableInt($request, 'object_id');
        if (! in_array($type, ReactionService::TYPES, true)) {
            abort(403);
        }
        if ($id === null) {
            abort(404);
        }

        try {
            $target = $this->reactions->target($type, $id);
            if (! $this->reactions->editable($actor, $target)) {
                throw new PermissionDeniedException('reactions');
            }
            if (! $this->scripted($request)) {
                abort(404);
            }
            if ($reactionId === null) {
                $this->reactions->react($actor, $target);
            } else {
                $this->reactions->unreact($actor, $target, $reactionId);
            }
        } catch (DomainException $exception) {
            if ($exception->getMessage() === ReactionService::MISSING) {
                abort(404);
            }

            return $this->http->fail($request, $exception);
        }

        $button = $this->reactions->button($actor, $target);

        return new JsonResponse(['reaction' => $button?->toArray()]);
    }

    private function scripted(Request $request): bool
    {
        return $request->ajax() || $request->wantsJson();
    }
}
