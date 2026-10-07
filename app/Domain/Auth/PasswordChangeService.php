<?php

namespace App\Domain\Auth;

use App\Models\User;

/**
 * Replaces `hashed_password` and `salt` for a local account.
 *
 * A successful write clears `must_change_passwd` and stamps `passwd_changed_on`.
 */
final class PasswordChangeService
{
    public function __construct(
        private readonly RedminePassword $passwords,
        private readonly PasswordPolicy $policy,
    ) {}

    public function change(User $user, string $current, string $password, string $confirmation): void
    {
        $errors = $this->localAccountErrors($user);
        $salt = is_string($user->salt) ? $user->salt : null;

        if ($errors === [] && ! $this->passwords->verify($current, $salt, $user->hashed_password)) {
            $errors['current_password'][] = AccountNotice::CURRENT_PASSWORD;
        }

        $errors = $this->merge($errors, $this->choiceErrors($user, $password, $confirmation, $salt));
        if ($errors !== []) {
            throw new AccountValidationException($errors);
        }

        $this->assign($user, $password);
    }

    /**
     * Stores a new digest without checking the current password.
     * Recovery calls this after the token and the policy have passed.
     */
    public function assign(User $user, string $password): void
    {
        $sealed = $this->passwords->seal($password);
        $user->forceFill([
            'salt' => $sealed['salt'],
            'hashed_password' => $sealed['hashed_password'],
            'must_change_passwd' => false,
            'passwd_changed_on' => now()->startOfSecond(),
        ])->save();
    }

    /**
     * @return array<string, list<string>>
     */
    public function choiceErrors(User $user, string $password, string $confirmation, ?string $salt): array
    {
        $errors = [];
        if ($password !== $confirmation) {
            $errors['password_confirmation'][] = 'Password confirmation does not match.';
        }

        foreach ($this->policy->failures($password) as $message) {
            $errors['password'][] = $message;
        }

        if ($user->must_change_passwd === true
            && $this->passwords->verify($password, $salt, $user->hashed_password)) {
            $errors['password'][] = AccountNotice::PASSWORD_MUST_DIFFER;
        }

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $left
     * @param  array<string, list<string>>  $right
     * @return array<string, list<string>>
     */
    private function merge(array $left, array $right): array
    {
        foreach ($right as $field => $messages) {
            foreach ($messages as $message) {
                $left[$field][] = $message;
            }
        }

        return $left;
    }

    /**
     * @return array<string, list<string>>
     */
    private function localAccountErrors(User $user): array
    {
        if ($user->type !== User::TYPE_USER || $user->auth_source_id !== null) {
            return [
                'password' => ['This account does not use a local password.'],
            ];
        }

        return [];
    }
}
