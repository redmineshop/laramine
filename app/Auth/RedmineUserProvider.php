<?php

namespace App\Auth;

use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\LoginDecision;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * Session-guard user provider for Redmine-shaped password columns.
 *
 * Laravel's hasher is not used to check or rewrite `hashed_password`.
 */
class RedmineUserProvider extends EloquentUserProvider
{
    public function __construct(
        Hasher $hasher,
        string $model,
        private readonly CredentialChecker $checker,
    ) {
        parent::__construct($hasher, $model);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?UserContract
    {
        $login = $credentials['login'] ?? null;
        if (! is_string($login)) {
            return null;
        }

        return $this->checker->findByIdentifier($login);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(UserContract $user, #[SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $password = $credentials['password'] ?? null;
        if (! is_string($password)) {
            return false;
        }

        return $this->checker->decideFor($user, $password) === LoginDecision::Accepted;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(UserContract $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        // `hashed_password` stays the Redmine digest. Do not replace it with bcrypt.
    }
}
