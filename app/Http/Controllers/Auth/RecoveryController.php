<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountNotice;
use App\Domain\Auth\AccountRecovery;
use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\ActionToken;
use App\Domain\Auth\TokenRejectedException;
use App\Domain\Settings\SettingValue;
use App\Http\Controllers\Controller;
use App\Models\Token;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RecoveryController extends Controller
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly AccountRecovery $recovery,
        private readonly ActionToken $tokens,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->settings->lostPasswordEnabled()) {
            return redirect('/');
        }

        $queryToken = $request->query('token');
        if (is_string($queryToken) && $queryToken !== '') {
            $request->session()->put('password_recovery_token', $queryToken);

            return redirect()->route('password.request');
        }

        $stored = $request->session()->get('password_recovery_token');
        $reset = false;
        if (is_string($stored) && $stored !== '') {
            $token = $this->tokens->findUsable(Token::ACTION_RECOVERY, $stored);
            if (! $token instanceof Token) {
                $request->session()->forget('password_recovery_token');

                return redirect('/');
            }
            $reset = true;
        }

        return view('auth.lost-password', [
            'reset' => $reset,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->settings->lostPasswordEnabled()) {
            return redirect('/');
        }

        $mail = $request->string('mail')->toString();
        if (trim($mail) === '') {
            throw ValidationException::withMessages([
                'mail' => 'Mail is required.',
            ]);
        }

        $this->recovery->request($mail);

        return redirect()
            ->route('password.request')
            ->with('status', AccountNotice::RECOVERY_SENT);
    }

    public function reset(Request $request): RedirectResponse
    {
        $stored = $request->session()->get('password_recovery_token');
        if (! is_string($stored) || $stored === '') {
            return redirect('/');
        }

        try {
            $this->recovery->reset(
                $stored,
                $request->string('password')->toString(),
                $request->string('password_confirmation')->toString(),
            );
        } catch (TokenRejectedException) {
            $request->session()->forget('password_recovery_token');

            return redirect('/');
        } catch (AccountValidationException $exception) {
            throw ValidationException::withMessages($exception->errors);
        }

        $request->session()->forget('password_recovery_token');

        return redirect()->route('login')->with('status', AccountNotice::PASSWORD_UPDATED);
    }
}
