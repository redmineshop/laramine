<?php

namespace App\Domain\Auth;

use App\Models\EmailAddress;
use App\Models\User;

/**
 * Resolves a sign-in identifier and decides whether a session may open.
 *
 * Login matches win over email matches. Phase 1 denies external-auth and
 * two-factor accounts instead of continuing those protocols.
 */
final class CredentialChecker
{
    public function __construct(
        private readonly RedminePassword $passwords,
    ) {}

    public function decide(string $identifier, string $password): LoginDecision
    {
        $user = $this->findByIdentifier($identifier);
        if (! $user instanceof User) {
            return LoginDecision::Unknown;
        }

        return $this->decideFor($user, $password);
    }

    public function findByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $byLogin = $this->matchesByLogin($identifier);
        if (count($byLogin) > 1) {
            return null;
        }
        if (count($byLogin) === 1) {
            return $byLogin[0];
        }

        return $this->matchByEmail($identifier);
    }

    public function decideFor(User $user, string $password): LoginDecision
    {
        if ($user->type !== User::TYPE_USER) {
            return LoginDecision::NotAccount;
        }

        if ($user->auth_source_id !== null) {
            return LoginDecision::ExternalAuth;
        }

        $salt = $user->salt;
        if (! $this->passwords->verify(
            $password,
            is_string($salt) ? $salt : null,
            $user->hashed_password,
        )) {
            return LoginDecision::Password;
        }

        if (! $user->isActive()) {
            return LoginDecision::Inactive;
        }

        if ($user->twoFactorGate()) {
            return LoginDecision::TwoFactor;
        }

        return LoginDecision::Accepted;
    }

    /**
     * @return list<User>
     */
    private function matchesByLogin(string $identifier): array
    {
        $rows = User::query()
            ->whereRaw('LOWER(login) = LOWER(?)', [$identifier])
            ->orderBy('id')
            ->limit(2)
            ->get();

        return array_values($rows->all());
    }

    private function matchByEmail(string $identifier): ?User
    {
        $ids = EmailAddress::query()
            ->whereRaw('LOWER(address) = LOWER(?)', [$identifier])
            ->distinct()
            ->limit(2)
            ->pluck('user_id');

        if ($ids->count() !== 1) {
            return null;
        }

        $id = $ids->first();
        if (! is_numeric($id)) {
            return null;
        }

        $user = User::query()->find((int) $id);

        return $user instanceof User ? $user : null;
    }
}
