<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Activity\ActivityProvider;
use App\Domain\Activity\AtomFeed;
use App\Domain\Auth\RestAuthenticator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Personal Atom feed authenticated by a `feeds` token.
 *
 * Entries are the caller's activity. An API key does not open this route.
 */
class FeedController extends Controller
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
        private readonly ActivityProvider $activity,
        private readonly AtomFeed $atom,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $this->authenticator->feedUser($request);
        if ($user === null) {
            return response('Unauthorized', 401);
        }

        $from = $request->query('from');
        $days = $request->query('days');
        $events = $this->activity->events(
            $user,
            null,
            is_string($from) ? $from : null,
            is_string($days) && preg_match('/^\d+$/', $days) === 1 ? (int) $days : null,
        );
        $body = $this->atom->render('Activity', $user, $events);

        return response($body, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }
}
