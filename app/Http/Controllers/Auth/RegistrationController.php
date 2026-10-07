<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountNotice;
use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\RegistrationClosedException;
use App\Domain\Auth\RegistrationService;
use App\Domain\Auth\SelfRegistrationMode;
use App\Domain\Auth\TokenRejectedException;
use App\Domain\Settings\SettingValue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly RegistrationService $registration,
    ) {}

    public function create(): View|RedirectResponse
    {
        if ($this->settings->selfRegistration() === SelfRegistrationMode::Disabled) {
            return redirect('/');
        }

        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        try {
            $result = $this->registration->register([
                'login' => $request->string('login')->toString(),
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
                'firstname' => $request->string('firstname')->toString(),
                'lastname' => $request->string('lastname')->toString(),
                'mail' => $request->string('mail')->toString(),
            ]);
        } catch (RegistrationClosedException) {
            return redirect('/');
        } catch (AccountValidationException $exception) {
            throw ValidationException::withMessages($exception->errors);
        }

        if ($result->mode === SelfRegistrationMode::Automatic) {
            Auth::login($result->user);
            $request->session()->regenerate();

            return redirect('/');
        }

        $notice = $result->mode === SelfRegistrationMode::Email
            ? AccountNotice::REGISTERED_EMAIL
            : AccountNotice::PENDING;

        return redirect()->route('login')->with('status', $notice);
    }

    public function activate(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        if (! is_string($token) || $token === '') {
            return redirect('/');
        }

        try {
            $this->registration->activate($token);
        } catch (TokenRejectedException) {
            return redirect('/');
        }

        $request->session()->forget('registered_user_id');

        return redirect()->route('login')->with('status', AccountNotice::ACTIVATED);
    }

    public function resend(Request $request): RedirectResponse
    {
        $id = $request->session()->get('registered_user_id');
        if (! is_numeric($id)) {
            throw ValidationException::withMessages([
                'login' => AccountNotice::INVALID,
            ]);
        }

        try {
            $this->registration->resendActivation((int) $id);
        } catch (TokenRejectedException) {
            throw ValidationException::withMessages([
                'login' => AccountNotice::INVALID,
            ]);
        }

        return redirect()
            ->route('login')
            ->withErrors(['login' => AccountNotice::NOT_ACTIVATED]);
    }
}
