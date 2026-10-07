<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Oauth\OauthException;
use App\Domain\Auth\Oauth\OauthProvider;
use App\Domain\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Authorization-code and refresh-token endpoints on the `oauth_*` tables.
 */
class OauthController extends Controller
{
    public function __construct(
        private readonly OauthProvider $oauth,
    ) {}

    public function authorizeForm(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        return view('oauth.authorize', [
            'query' => $request->query(),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        try {
            $target = $this->oauth->authorize($user, $request->all(), $request->boolean('approve'));
        } catch (OauthException $exception) {
            abort(400, $exception->getMessage());
        }

        return redirect()->away($target);
    }

    public function token(Request $request): JsonResponse
    {
        try {
            return response()->json($this->oauth->token($request->all()));
        } catch (OauthException $exception) {
            $status = $exception->error === 'invalid_client' ? 401 : 400;

            return response()->json(['error' => $exception->error], $status);
        }
    }

    public function revoke(Request $request): Response|JsonResponse
    {
        try {
            $this->oauth->revoke($request->all());
        } catch (OauthException $exception) {
            return response()->json(['error' => $exception->error], 401);
        }

        return response()->noContent();
    }

    public function storeApplication(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        try {
            $created = $this->oauth->registerApplication(
                $user,
                $request->string('name')->toString(),
                $request->string('redirect_uri')->toString(),
                $request->string('scopes')->toString(),
                $request->boolean('confidential', true),
            );
        } catch (PermissionDeniedException) {
            abort(403);
        } catch (OauthException $exception) {
            return response()->json(['error' => $exception->error], 422);
        }

        return response()->json([
            'uid' => $created['application']->uid,
            'secret' => $created['secret'],
        ], 201);
    }
}
