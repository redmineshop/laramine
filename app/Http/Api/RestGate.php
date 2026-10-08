<?php

namespace App\Http\Api;

use App\Domain\Auth\RestAuthenticator;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a `.json` or `.xml` request and renders auth failures.
 */
final class RestGate
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
        private readonly ApiResponder $responder,
    ) {}

    public function user(Request $request): User|Response
    {
        $format = $this->format($request);
        $result = $this->authenticator->authenticate($request);
        if ($result->user instanceof User) {
            return $result->user;
        }
        if ($result->status === 412) {
            return $this->responder->send($format, ApiResult::message(412, 'error', 'User impersonation failed'));
        }

        return $this->responder->send($format, ApiResult::fail(401, 'Unauthorized'));
    }

    public function format(Request $request): string
    {
        return $request->route('format') === 'xml' ? 'xml' : 'json';
    }
}
