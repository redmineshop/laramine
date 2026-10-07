<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\RestAuthenticator;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Atom document authenticated by a `feeds` token.
 *
 * This is the key gate. It does not list activity entries.
 */
class FeedController extends Controller
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $this->authenticator->feedUser($request);
        if ($user === null) {
            return response('Unauthorized', 401);
        }

        $login = htmlspecialchars((string) $user->login, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>Account feed</title>
              <author><name>{$login}</name></author>
            </feed>
            XML;

        return response($body, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }
}
