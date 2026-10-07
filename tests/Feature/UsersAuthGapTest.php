<?php

namespace Tests\Feature;

use App\Domain\Auth\AdministratorSeed;
use App\Domain\Auth\RedminePassword;
use App\Domain\Settings\SettingValue;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersAuthGapTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_seed_stays_closed_until_a_password_is_supplied(): void
    {
        $previousPassword = getenv('LARAMINE_ADMIN_PASSWORD');
        $previousLogin = getenv('LARAMINE_ADMIN_LOGIN');
        putenv('LARAMINE_ADMIN_PASSWORD');
        putenv('LARAMINE_ADMIN_LOGIN');

        try {
            $closed = app(AdministratorSeed::class)->apply();
            $this->assertFalse($closed->admin);
            $this->assertSame(RedminePassword::PLACEHOLDER_HASH, $closed->hashed_password);
            $this->assertSame('admin', $closed->login);

            putenv('LARAMINE_ADMIN_PASSWORD=secret-2');
            putenv('LARAMINE_ADMIN_LOGIN=seeded-admin');
            $opened = app(AdministratorSeed::class)->apply();
            $this->assertTrue($opened->admin);
            $this->assertSame('seeded-admin', $opened->login);
            $this->assertTrue((new RedminePassword)->verify('secret-2', $opened->salt, $opened->hashed_password));
        } finally {
            $this->restoreEnv('LARAMINE_ADMIN_PASSWORD', $previousPassword);
            $this->restoreEnv('LARAMINE_ADMIN_LOGIN', $previousLogin);
        }
    }

    public function test_acting_as_is_not_logged_out_by_session_timeout(): void
    {
        $this->withoutVite();
        Setting::query()->updateOrCreate(
            ['name' => SettingValue::SESSION_TIMEOUT],
            ['value' => '1'],
        );
        $user = User::factory()->withPassword('secret-2')->create([
            'login' => 'acting',
        ]);

        $this->actingAs($user);
        $this->travel(10)->minutes();
        $this->get('/')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_non_admin_cannot_create_an_account(): void
    {
        $user = User::factory()->withPassword('secret-2')->create([
            'login' => 'member',
            'admin' => false,
        ]);

        $this->actingAs($user)->get('/users/new')->assertForbidden();
        $this->actingAs($user)->post('/users', [
            'login' => 'nina',
            'firstname' => 'Nina',
            'lastname' => 'New',
            'mail' => 'nina@example.test',
            'password' => 'secret-2',
            'password_confirmation' => 'secret-2',
        ])->assertForbidden();
        $this->assertNull(User::query()->where('login', 'nina')->first());
    }

    private function restoreEnv(string $name, string|false $previous): void
    {
        if (! is_string($previous) || $previous === '') {
            putenv($name);

            return;
        }

        putenv($name.'='.$previous);
    }
}
