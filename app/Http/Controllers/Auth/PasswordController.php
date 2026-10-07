<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\AccountNotice;
use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\PasswordChangeService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordChangeService $passwords,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('auth.password', [
            'mustChange' => $user instanceof User && $user->must_change_passwd === true,
            'mustChangeMessage' => AccountNotice::MUST_CHANGE,
        ]);
    }

    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        try {
            $this->passwords->change(
                $user,
                $request->string('current_password')->toString(),
                $request->string('password')->toString(),
                $request->string('password_confirmation')->toString(),
            );
        } catch (AccountValidationException $exception) {
            throw ValidationException::withMessages($exception->errors);
        }

        return redirect('/')->with('status', AccountNotice::PASSWORD_UPDATED);
    }
}
