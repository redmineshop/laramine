<?php

namespace App\Domain\Auth;

use App\Domain\Notifications\AccountNotifier;
use App\Domain\Settings\SettingValue;
use App\Models\EmailAddress;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Password recovery through `email_addresses` and a `tokens.action = recovery` row.
 *
 * The HTTP notice is the same whether or not a token was stored. A registered
 * account in email-activation mode receives a fresh `register` token instead.
 */
final class AccountRecovery
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly ActionToken $tokens,
        private readonly PasswordChangeService $passwords,
        private readonly AccountNotifier $mail,
    ) {}

    /**
     * True when the lost-password setting allows the request.
     * A false result means the feature is off. It does not mean the address was unknown.
     */
    public function request(string $mail): bool
    {
        if (! $this->settings->lostPasswordEnabled()) {
            return false;
        }

        $mail = trim($mail);
        if ($mail !== '') {
            $this->issueForMail($mail);
        }

        return true;
    }

    public function reset(string $tokenValue, string $password, string $confirmation): User
    {
        $token = $this->tokens->findUsable(Token::ACTION_RECOVERY, $tokenValue);
        $user = $token instanceof Token ? $token->user : null;
        if (! $token instanceof Token || ! $user instanceof User || ! $this->canReset($user)) {
            throw new TokenRejectedException('Recovery token is not usable.');
        }

        $salt = is_string($user->salt) ? $user->salt : null;
        $errors = $this->passwords->choiceErrors($user, $password, $confirmation, $salt);
        if ($errors !== []) {
            throw new AccountValidationException($errors);
        }

        DB::transaction(function () use ($user, $password, $token): void {
            $this->passwords->assign($user, $password);
            $this->tokens->consume($token);
        });

        $fresh = $user->fresh();
        if (! $fresh instanceof User) {
            throw new TokenRejectedException('Recovery token is not usable.');
        }

        return $fresh;
    }

    private function issueForMail(string $mail): void
    {
        $ids = EmailAddress::query()
            ->whereRaw('LOWER(address) = LOWER(?)', [$mail])
            ->distinct()
            ->limit(2)
            ->pluck('user_id');

        if ($ids->count() !== 1) {
            return;
        }

        $id = $ids->first();
        if (! is_numeric($id)) {
            return;
        }

        $user = User::query()->find((int) $id);
        if (! $user instanceof User || $user->type !== User::TYPE_USER || $user->auth_source_id !== null) {
            return;
        }

        if ($user->isActive()) {
            $this->mail->lostPassword($user, $this->tokens->issue($user, Token::ACTION_RECOVERY));

            return;
        }

        if ((int) $user->status === User::STATUS_REGISTERED
            && $this->settings->selfRegistration() === SelfRegistrationMode::Email) {
            $this->mail->activation($user, $this->tokens->issue($user, Token::ACTION_REGISTER));
        }
    }

    private function canReset(User $user): bool
    {
        return $user->type === User::TYPE_USER
            && $user->auth_source_id === null
            && $user->isActive();
    }
}
