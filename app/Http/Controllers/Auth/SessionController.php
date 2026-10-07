<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountNotice;
use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\LoginDecision;
use App\Domain\Auth\SelfRegistrationMode;
use App\Domain\Settings\SettingValue;
use App\Http\Auth\PasswordChangeResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    public function __construct(
        private readonly CredentialChecker $checker,
        private readonly AccountNotice $notices,
        private readonly SettingValue $settings,
    ) {}

    /**
     * Inertia sign-in page. The Blade form stays at `?view=blade`.
     */
    public function create(Request $request): InertiaResponse|View
    {
        $props = $this->loginProps($request);

        if ($request->query('view') === 'blade') {
            return view('auth.login', $props);
        }

        return Inertia::render('Auth/Login', $props);
    }

    public function store(LoginRequest $request): JsonResponse|RedirectResponse|Response
    {
        $identifier = $request->string('login')->toString();
        $password = $request->string('password')->toString();
        $accepted = Auth::attempt([
            'login' => $identifier,
            'password' => $password,
        ]);

        if (! $accepted) {
            $user = $this->checker->findByIdentifier($identifier);
            $decision = $user instanceof User
                ? $this->checker->decideFor($user, $password)
                : LoginDecision::Unknown;
            $this->rememberRegisteredAccount($request, $user, $decision);

            throw ValidationException::withMessages([
                'login' => $this->notices->signInFailure($decision),
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget('registered_user_id');

        $user = $request->user();
        $mustChange = $user instanceof User && $user->must_change_passwd === true;

        if ($request->expectsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user?->getAuthIdentifier(),
                    'login' => $user instanceof User ? (string) $user->login : null,
                ],
                'must_change_passwd' => $mustChange,
            ]);
        }

        if ($mustChange) {
            return PasswordChangeResponse::toForm($request);
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

    /**
     * @return array{
     *     submitUrl: string,
     *     lostPasswordUrl: string|null,
     *     registerUrl: string|null,
     *     activationEmailUrl: string|null,
     *     notice: string|null
     * }
     */
    private function loginProps(Request $request): array
    {
        $notice = $request->session()->get('status');

        return [
            'submitUrl' => route('login', [], false),
            'lostPasswordUrl' => $this->settings->lostPasswordEnabled()
                ? route('password.request', [], false)
                : null,
            'registerUrl' => $this->settings->selfRegistration() === SelfRegistrationMode::Disabled
                ? null
                : route('register', [], false),
            'activationEmailUrl' => is_numeric($request->session()->get('registered_user_id'))
                ? route('account.activation-email', [], false)
                : null,
            'notice' => is_string($notice) && $notice !== '' ? $notice : null,
        ];
    }

    private function rememberRegisteredAccount(Request $request, ?User $user, LoginDecision $decision): void
    {
        if ($decision === LoginDecision::Registered
            && $user instanceof User
            && $this->settings->selfRegistration() === SelfRegistrationMode::Email) {
            $request->session()->put('registered_user_id', $user->id);

            return;
        }

        $request->session()->forget('registered_user_id');
    }
}
