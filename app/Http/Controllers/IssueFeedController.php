<?php

namespace App\Http\Controllers;

use App\Domain\Activity\ActivityProvider;
use App\Domain\Activity\AtomFeed;
use App\Domain\Auth\RestAuthenticator;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Atom issue list keyed by a `feeds` token or the signed-in session.
 */
class IssueFeedController extends Controller
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
        private readonly ActivityProvider $activity,
        private readonly AtomFeed $atom,
    ) {}

    public function __invoke(Request $request, ?string $identifier = null): Response
    {
        $user = $this->authenticator->feedUser($request);
        if ($user === null) {
            return response('Unauthorized', 401);
        }
        $project = null;
        if ($identifier !== null && $identifier !== '') {
            $found = Project::query()->where('identifier', $identifier)->first();
            if (! $found instanceof Project) {
                return response('Not found', 404);
            }
            $project = $found;
        }

        $events = $this->activity->issueList($user, $project);
        $title = $project instanceof Project ? $project->name.' issues' : 'Issues';
        $body = $this->atom->render($title, $user, $events);

        return response($body, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }
}
