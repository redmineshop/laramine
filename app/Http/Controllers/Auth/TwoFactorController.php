<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\TwoFactorPolicy;
use App\Domain\Auth\TwoFactorService;
use App\Domain\Auth\WebSession;
use App\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TOTP challenge for a password that already succeeded, and enrollment for a signed-in account.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TwoFactorPolicy $policy,
        private readonly WebSession $sessions,
    ) {}

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->sessions->pendingTwoFactor($request);
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $secret = null;
        if ($this->policy->mustEnroll($user)) {
            $stored = $request->session()->get('twofa_pending_secret');
            if (! is_string($stored) || $stored === '') {
                try {
                    $stored = $this->twoFactor->beginSecret();
                } catch (DomainException) {
                    return redirect()->route('login');
                }
                $request->session()->put('twofa_pending_secret', $stored);
            }
            $secret = $stored;
        }

        return view('auth.twofa', [
            'mustEnroll' => $secret !== null,
            'secret' => $secret,
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->sessions->pendingTwoFactor($request);
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $code = $request->string('code')->toString();
        $secret = $request->session()->get('twofa_pending_secret');
        $remember = $this->sessions->pendingRemember($request);
        $backupCodes = null;
        try {
            if (is_string($secret) && $secret !== '' && $this->policy->mustEnroll($user)) {
                $backupCodes = $this->twoFactor->confirm($user, $secret, $code, now()->getTimestamp());
            } elseif (! $this->twoFactor->verify($user, $code, now()->getTimestamp())) {
                return back()->withErrors(['code' => 'The code is invalid.']);
            }
        } catch (AccountValidationException $exception) {
            return back()->withErrors($exception->errors);
        } catch (DomainException) {
            return redirect()->route('login');
        }

        $request->session()->forget('twofa_pending_secret');
        $this->sessions->open($request, $user->refresh(), $remember);
        $redirect = redirect()->intended('/');
        if ($backupCodes !== null) {
            $redirect->with('backup_codes', $backupCodes);
        }

        return $redirect;
    }

    public function backup(Request $request): RedirectResponse
    {
        $user = $this->sessions->pendingTwoFactor($request);
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if (! $this->twoFactor->verifyBackup($user, $request->string('backup_code')->toString())) {
            return back()->withErrors(['backup_code' => 'The backup code is invalid.']);
        }

        $remember = $this->sessions->pendingRemember($request);
        $request->session()->forget('twofa_pending_secret');
        $this->sessions->open($request, $user->refresh(), $remember);

        return redirect()->intended('/');
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }
        if ($this->policy->mode() === TwoFactorPolicy::DISABLED) {
            return redirect('/')->withErrors(['code' => 'Two-factor authentication is disabled.']);
        }

        $secret = $request->session()->get('twofa_pending_secret');
        if (! is_string($secret) || $secret === '') {
            $secret = $this->twoFactor->beginSecret();
            $request->session()->put('twofa_pending_secret', $secret);
        }

        return view('auth.twofa-enroll', [
            'secret' => $secret,
            'enabled' => $this->policy->schemeEnabled($user),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $secret = $request->session()->get('twofa_pending_secret');
        if (! is_string($secret) || $secret === '') {
            return redirect()->route('twofa.edit');
        }

        try {
            $codes = $this->twoFactor->confirm($user, $secret, $request->string('code')->toString(), now()->getTimestamp());
        } catch (AccountValidationException $exception) {
            return back()->withErrors($exception->errors);
        } catch (DomainException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()]);
        }

        $request->session()->forget('twofa_pending_secret');

        return redirect()->route('twofa.edit')->with('backup_codes', $codes);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->twoFactor->disable($user);
        }

        return redirect()->route('twofa.edit');
    }
}
