<?php

namespace App\Domain\Auth;

use App\Models\User;

/**
 * Seeds the `admin` login.
 *
 * Without `LARAMINE_ADMIN_PASSWORD` the row keeps the placeholder digest and
 * `admin = false`, so it cannot sign in. A password supplied from the
 * environment seals `hashed_password` and sets `admin = true`. The password
 * is not stored in the repository.
 */
final class AdministratorSeed
{
    public function __construct(
        private readonly RedminePassword $passwords,
    ) {}

    public function apply(): User
    {
        $login = getenv('LARAMINE_ADMIN_LOGIN');
        $login = is_string($login) && trim($login) !== '' ? trim($login) : 'admin';
        $password = getenv('LARAMINE_ADMIN_PASSWORD');
        if (! is_string($password) || $password === '') {
            return User::factory()->create([
                'login' => $login,
                'firstname' => 'Test',
                'lastname' => 'User',
                'admin' => false,
            ]);
        }

        $sealed = $this->passwords->seal($password);

        return User::factory()->create([
            'login' => $login,
            'firstname' => 'Admin',
            'lastname' => 'User',
            'admin' => true,
            'hashed_password' => $sealed['hashed_password'],
            'salt' => $sealed['salt'],
        ]);
    }
}
