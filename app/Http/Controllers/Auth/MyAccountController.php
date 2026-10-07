<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountPreferences;
use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\ActionToken;
use App\Domain\Auth\PreferenceCodec;
use App\Domain\PermissionDeniedException;
use App\Http\Controllers\Controller;
use App\Models\Token;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Signed-in preferences and the single `api` and `feeds` keys.
 */
class MyAccountController extends Controller
{
    public function __construct(
        private readonly AccountPreferences $preferences,
        private readonly PreferenceCodec $codec,
        private readonly ActionToken $tokens,
    ) {}

    public function edit(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $preference = $user->preference;

        return view('auth.account', [
            'user' => $user,
            'hideMail' => $preference !== null && $preference->hide_mail === true,
            'timeZone' => $preference?->time_zone,
            'others' => $this->codec->decode(is_string($preference?->others) ? $preference->others : null),
            'apiKey' => $request->session()->get('api_key'),
            'atomKey' => $request->session()->get('atom_key'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $others = [];
        foreach (['comments_sorting', 'textarea_font', 'history_default_tab'] as $key) {
            $value = $request->input($key);
            if (is_string($value) && $value !== '') {
                $others[$key] = $value;
            }
        }
        if ($request->boolean('warn_on_leaving_unsaved')) {
            $others['warn_on_leaving_unsaved'] = true;
        }
        if ($request->boolean('no_self_notified')) {
            $others['no_self_notified'] = true;
        }

        try {
            $this->preferences->update($user, $user, [
                'mail_notification' => $request->string('mail_notification')->toString(),
                'hide_mail' => $request->boolean('hide_mail'),
                'time_zone' => $request->input('time_zone'),
                'others' => $others,
            ]);
        } catch (AccountValidationException $exception) {
            return back()->withErrors($exception->errors);
        } catch (PermissionDeniedException) {
            abort(403);
        }

        return redirect()->route('account.edit');
    }

    public function apiKey(Request $request): RedirectResponse
    {
        return $this->issue($request, Token::ACTION_API, 'api_key');
    }

    public function atomKey(Request $request): RedirectResponse
    {
        return $this->issue($request, Token::ACTION_FEEDS, 'atom_key');
    }

    private function issue(Request $request, string $action, string $flash): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $token = $this->tokens->issueNamed($user, $action);

        return redirect()->route('account.edit')->with($flash, $token->value);
    }
}
