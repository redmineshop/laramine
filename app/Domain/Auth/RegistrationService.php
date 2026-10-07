<?php

namespace App\Domain\Auth;

use App\Domain\Settings\SettingValue;
use App\Models\EmailAddress;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * Self-registration and the `register` token that activates a status-2 account.
 *
 * Outbound mail is not sent. Email mode stores the token for a later mailer.
 */
final class RegistrationService
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly RedminePassword $passwords,
        private readonly PasswordPolicy $policy,
        private readonly ActionToken $tokens,
    ) {}

    /**
     * @param  array{login: string, password: string, password_confirmation: string, firstname: string, lastname: string, mail: string}  $input
     */
    public function register(array $input): RegistrationResult
    {
        $mode = $this->settings->selfRegistration();
        if ($mode === SelfRegistrationMode::Disabled) {
            throw new RegistrationClosedException;
        }

        $login = trim($input['login']);
        $firstname = trim($input['firstname']);
        $lastname = trim($input['lastname']);
        $mail = trim($input['mail']);
        $password = $input['password'];
        $confirmation = $input['password_confirmation'];

        $errors = $this->fieldErrors($login, $firstname, $lastname, $mail, $password, $confirmation);
        if ($errors !== []) {
            throw new AccountValidationException($errors);
        }

        return DB::transaction(function () use ($login, $firstname, $lastname, $mail, $password, $mode): RegistrationResult {
            $sealed = $this->passwords->seal($password);
            $user = new User;
            $user->forceFill([
                'login' => $login,
                'hashed_password' => $sealed['hashed_password'],
                'salt' => $sealed['salt'],
                'firstname' => $firstname,
                'lastname' => $lastname,
                'admin' => false,
                'status' => $mode === SelfRegistrationMode::Automatic
                    ? User::STATUS_ACTIVE
                    : User::STATUS_REGISTERED,
                'type' => User::TYPE_USER,
                'language' => 'en',
                'mail_notification' => $this->settings->defaultMailNotification(),
                'must_change_passwd' => false,
                'passwd_changed_on' => now()->startOfSecond(),
                'auth_source_id' => null,
            ])->save();

            EmailAddress::query()->create([
                'user_id' => $user->id,
                'address' => $mail,
                'is_default' => true,
                'notify' => true,
            ]);

            UserPreference::query()->create([
                'user_id' => $user->id,
                'hide_mail' => true,
            ]);

            $token = null;
            if ($mode === SelfRegistrationMode::Email) {
                $token = $this->tokens->issue($user, Token::ACTION_REGISTER);
            }

            return new RegistrationResult($user, $mode, $token);
        });
    }

    public function activate(string $tokenValue): User
    {
        if ($this->settings->selfRegistration() === SelfRegistrationMode::Disabled) {
            throw new TokenRejectedException('Registration is closed.');
        }

        $token = $this->tokens->findUsable(Token::ACTION_REGISTER, $tokenValue);
        $user = $token instanceof Token ? $token->user : null;
        if (! $token instanceof Token || ! $user instanceof User || ! $this->canActivate($user)) {
            throw new TokenRejectedException('Register token is not usable.');
        }

        DB::transaction(function () use ($user, $token): void {
            $user->forceFill(['status' => User::STATUS_ACTIVE])->save();
            $this->tokens->consume($token);
        });

        $fresh = $user->fresh();
        if (! $fresh instanceof User) {
            throw new TokenRejectedException('Register token is not usable.');
        }

        return $fresh;
    }

    public function resendActivation(int $userId): void
    {
        if ($this->settings->selfRegistration() !== SelfRegistrationMode::Email) {
            throw new TokenRejectedException('Activation email is not available.');
        }

        $user = User::query()->find($userId);
        if (! $user instanceof User || ! $this->canActivate($user)) {
            throw new TokenRejectedException('Activation email is not available.');
        }

        $this->tokens->issue($user, Token::ACTION_REGISTER);
    }

    /**
     * @return array<string, list<string>>
     */
    private function fieldErrors(
        string $login,
        string $firstname,
        string $lastname,
        string $mail,
        string $password,
        string $confirmation,
    ): array {
        $errors = [];

        if ($login === '') {
            $errors['login'][] = 'Login is required.';
        } elseif (mb_strlen($login) > 60 || preg_match('/^[A-Za-z0-9_\-@.]+$/', $login) !== 1) {
            $errors['login'][] = 'Login is invalid.';
        } elseif (User::query()->whereRaw('LOWER(login) = LOWER(?)', [$login])->exists()) {
            $errors['login'][] = 'Login has already been taken.';
        }

        if ($firstname === '') {
            $errors['firstname'][] = 'First name is required.';
        } elseif (mb_strlen($firstname) > 30) {
            $errors['firstname'][] = 'First name is too long.';
        }

        if ($lastname === '') {
            $errors['lastname'][] = 'Last name is required.';
        } elseif (mb_strlen($lastname) > 255) {
            $errors['lastname'][] = 'Last name is too long.';
        }

        if ($mail === '') {
            $errors['mail'][] = 'Email is required.';
        } elseif (mb_strlen($mail) > 60 || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['mail'][] = 'Email is invalid.';
        } elseif (EmailAddress::query()->whereRaw('LOWER(address) = LOWER(?)', [$mail])->exists()) {
            $errors['mail'][] = 'Email has already been taken.';
        }

        if ($password !== $confirmation) {
            $errors['password_confirmation'][] = 'Password confirmation does not match.';
        }

        foreach ($this->policy->failures($password) as $message) {
            $errors['password'][] = $message;
        }

        return $errors;
    }

    private function canActivate(User $user): bool
    {
        return $user->type === User::TYPE_USER
            && (int) $user->status === User::STATUS_REGISTERED;
    }
}
