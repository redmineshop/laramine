<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Ldap\LdapAuthenticator;
use App\Models\EmailAddress;
use App\Models\User;

/**
 * Resolves a sign-in identifier and decides whether a session may open.
 *
 * Login matches win over email matches. An `auth_source_id` is checked
 * against that LDAP source. A second factor is reported after the password
 * succeeds when the `twofa` setting requires it.
 */
final class CredentialChecker
{
    public function __construct(
        private readonly RedminePassword $passwords,
        private readonly LdapAuthenticator $ldap,
        private readonly TwoFactorPolicy $twoFactor,
    ) {}

    public function decide(string $identifier, string $password): LoginDecision
    {
        $existing = $this->findByIdentifier($identifier);
        if (! $existing instanceof User) {
            $this->ldap->provision($identifier, $password);
        }

        $user = $existing ?? $this->findByIdentifier($identifier);
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
            $external = $this->ldap->check($user, $password);
            if ($external !== LoginDecision::Accepted) {
                return $external;
            }
        } else {
            $salt = $user->salt;
            if (! $this->passwords->verify(
                $password,
                is_string($salt) ? $salt : null,
                $user->hashed_password,
            )) {
                return LoginDecision::Password;
            }
        }

        if (! $user->isActive()) {
            return match ((int) $user->status) {
                User::STATUS_LOCKED => LoginDecision::Locked,
                User::STATUS_REGISTERED => LoginDecision::Registered,
                default => LoginDecision::Inactive,
            };
        }

        if ($this->twoFactor->challengeRequired($user)) {
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
