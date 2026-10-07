<?php

namespace App\Http\Controllers;

use App\Domain\Activity\ActivityProvider;
use App\Domain\Activity\AtomFeed;
use App\Domain\Auth\RestAuthenticator;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Activity page and Atom feed for issues, journals, time entries, wiki edits, and messages.
 *
 * The feed accepts a `feeds` token in `key`, or the signed-in session.
 * An API key does not open it.
 */
class ActivityController extends Controller
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
        private readonly ActivityProvider $activity,
        private readonly AtomFeed $atom,
    ) {}

    public function index(Request $request, ?string $identifier = null): Response|View
    {
        $user = $this->authenticator->feedUser($request);
        if ($user === null) {
            return response('Unauthorized', 401);
        }
        $project = $this->project($identifier);
        if ($identifier !== null && ! $project instanceof Project) {
            return response('Not found', 404);
        }

        $events = $this->activity->events(
            $user,
            $project,
            $request->query('from') === null ? null : (string) $request->query('from'),
            $this->days($request),
        );

        return view('activity.index', [
            'login' => $user->login,
            'project' => $project?->identifier,
            'events' => $events,
        ]);
    }

    public function atom(Request $request, ?string $identifier = null): Response
    {
        $user = $this->authenticator->feedUser($request);
        if ($user === null) {
            return response('Unauthorized', 401);
        }
        $project = $this->project($identifier);
        if ($identifier !== null && ! $project instanceof Project) {
            return response('Not found', 404);
        }

        $events = $this->activity->events(
            $user,
            $project,
            $request->query('from') === null ? null : (string) $request->query('from'),
            $this->days($request),
        );
        $title = $project instanceof Project ? $project->name.' activity' : 'Activity';
        $body = $this->atom->render($title, $user, $events);

        return response($body, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }

    private function project(?string $identifier): ?Project
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        $project = Project::query()->where('identifier', $identifier)->first();

        return $project instanceof Project ? $project : null;
    }

    private function days(Request $request): ?int
    {
        $days = $request->query('days');
        if (! is_string($days) || preg_match('/^\d+$/', $days) !== 1) {
            return null;
        }

        return (int) $days;
    }
}
