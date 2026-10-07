<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\RestAuthenticator;
use App\Http\Controllers\Controller;
use App\Models\EmailAddress;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /users/current.json` is the REST authentication probe.
 *
 * The rest of the Redmine REST API is not this route.
 */
class RestUserController extends Controller
{
    public function __construct(
        private readonly RestAuthenticator $authenticator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->authenticator->authenticate($request);
        if ($result->status === 412) {
            return response()->json(['error' => 'User impersonation failed'], 412);
        }
        $user = $result->user;
        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $mail = EmailAddress::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->value('address');

        return response()->json([
            'user' => [
                'id' => (int) $user->id,
                'login' => (string) $user->login,
                'firstname' => (string) $user->firstname,
                'lastname' => (string) $user->lastname,
                'mail' => is_string($mail) ? $mail : null,
                'admin' => $user->admin === true,
                'status' => (int) $user->status,
            ],
        ]);
    }
}
