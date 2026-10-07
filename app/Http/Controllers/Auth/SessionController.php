<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $accepted = Auth::attempt([
            'login' => $request->string('login')->toString(),
            'password' => $request->string('password')->toString(),
        ]);

        if (! $accepted) {
            throw ValidationException::withMessages([
                'login' => 'Invalid user or password.',
            ]);
        }

        $request->session()->regenerate();

        if ($request->expectsJson()) {
            $user = $request->user();

            return response()->json([
                'user' => [
                    'id' => $user?->getAuthIdentifier(),
                    'login' => $user instanceof User ? (string) $user->login : null,
                ],
            ]);
        }

        return redirect()->intended('/');
    }

    public function destroy(Request $request): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()->route('login');
    }
}
