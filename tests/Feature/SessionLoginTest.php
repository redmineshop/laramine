<?php

namespace Tests\Feature;

use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\LoginDecision;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SessionLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_signs_in_and_out_without_rewriting_the_digest(): void
    {
        $user = $this->account();
        $digest = $user->hashed_password;

        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('redmine', config('auth.providers.users.driver'));

        $this->post('/login', [
            'login' => 'Ada',
            'password' => 'secret',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->last_login_on);
        $this->assertSame($digest, $fresh->hashed_password);
        $this->assertSame(1, Token::query()->where('action', Token::ACTION_SESSION)->count());
        $this->assertSame(0, Token::query()->where('action', '!=', Token::ACTION_SESSION)->count());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_json_sign_in_and_sign_out(): void
    {
        $user = $this->account();

        $this->postJson('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertOk()->assertJsonPath('user.login', 'ada');

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_sign_in_accepts_a_non_default_email(): void
    {
        $user = $this->account();
        EmailAddress::query()->create([
            'user_id' => $user->id,
            'address' => 'Ada@Example.test',
            'is_default' => false,
            'notify' => true,
        ]);

        $this->post('/login', [
            'login' => ' ada@example.test ',
            'password' => 'secret',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_match_wins_over_another_users_email(): void
    {
        $owner = $this->account(['login' => 'owner']);
        $other = $this->account(['login' => 'other']);
        EmailAddress::query()->create([
            'user_id' => $other->id,
            'address' => 'owner',
            'is_default' => true,
            'notify' => true,
        ]);

        $this->post('/login', [
            'login' => 'owner',
            'password' => 'secret',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);
    }

    public function test_wrong_password_does_not_start_a_session(): void
    {
        $user = $this->account();

        $this->from('/login')->post('/login', [
            'login' => 'ada',
            'password' => 'wrong',
        ])->assertRedirect('/login')->assertSessionHasErrors([
            'login' => 'Invalid user or password',
        ]);

        $this->assertGuest();
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->last_login_on);
        $this->assertSame(LoginDecision::Password, app(CredentialChecker::class)->decide('ada', 'wrong'));
    }

    public function test_placeholder_digest_is_not_a_credential(): void
    {
        $user = User::factory()->create(['login' => 'placeholder']);

        $this->from('/login')->post('/login', [
            'login' => 'placeholder',
            'password' => 'secret',
        ])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(LoginDecision::Password, app(CredentialChecker::class)->decide('placeholder', 'secret'));
        $this->assertSame(User::factory()->make()->hashed_password, $user->hashed_password);
    }

    public function test_inactive_statuses_do_not_start_a_session(): void
    {
        $cases = [
            User::STATUS_ANONYMOUS => [LoginDecision::Inactive, 'Invalid user or password'],
            User::STATUS_REGISTERED => [LoginDecision::Registered, 'Your account was created and is now pending administrator approval.'],
            User::STATUS_LOCKED => [LoginDecision::Locked, 'Your account is locked.'],
        ];

        foreach ($cases as $status => [$decision, $message]) {
            $login = 'idle-'.$status;
            $this->account(['login' => $login, 'status' => $status]);

            $this->from('/login')->post('/login', [
                'login' => $login,
                'password' => 'secret',
            ])->assertRedirect('/login')->assertSessionHasErrors([
                'login' => $message,
            ]);

            $this->assertGuest();
            $this->assertSame($decision, app(CredentialChecker::class)->decide($login, 'secret'));
            $this->assertSame(LoginDecision::Password, app(CredentialChecker::class)->decide($login, 'wrong'));

            $this->from('/login')->post('/login', [
                'login' => $login,
                'password' => 'wrong',
            ])->assertSessionHasErrors([
                'login' => 'Invalid user or password',
            ]);
        }
    }

    public function test_anonymous_and_group_rows_do_not_start_a_session(): void
    {
        $this->account([
            'login' => 'guest',
            'type' => User::TYPE_ANONYMOUS,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->account([
            'login' => 'ops',
            'type' => User::TYPE_GROUP,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->from('/login')->post('/login', [
            'login' => 'guest',
            'password' => 'secret',
        ])->assertRedirect('/login');
        $this->from('/login')->post('/login', [
            'login' => 'ops',
            'password' => 'secret',
        ])->assertRedirect('/login');

        $this->assertGuest();
        $checker = app(CredentialChecker::class);
        $this->assertSame(LoginDecision::NotAccount, $checker->decide('guest', 'secret'));
        $this->assertSame(LoginDecision::NotAccount, $checker->decide('ops', 'secret'));
    }

    public function test_external_auth_without_a_reachable_directory_does_not_start_a_session(): void
    {
        $source = AuthSource::query()->create([
            'name' => 'Directory',
            'type' => 'AuthSourceLdap',
        ]);
        $this->account([
            'login' => 'ldap-user',
            'auth_source_id' => $source->id,
        ]);

        $this->from('/login')->post('/login', [
            'login' => 'ldap-user',
            'password' => 'secret',
        ])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(LoginDecision::ExternalAuth, app(CredentialChecker::class)->decide('ldap-user', 'secret'));
    }

    public function test_two_factor_columns_are_ignored_while_the_setting_is_off(): void
    {
        $user = $this->account([
            'login' => 'totp-user',
            'twofa_scheme' => 'totp',
            'twofa_totp_key' => 'SECRET',
        ]);

        $this->post('/login', [
            'login' => 'totp-user',
            'password' => 'secret',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_ambiguous_login_does_not_sign_in(): void
    {
        $this->account(['login' => 'Ada']);
        $this->account(['login' => 'ada']);

        $this->from('/login')->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(LoginDecision::Unknown, app(CredentialChecker::class)->decide('ada', 'secret'));
    }

    public function test_locked_account_loses_an_existing_session(): void
    {
        $user = $this->account();
        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertRedirect('/');

        $user->forceFill(['status' => User::STATUS_LOCKED])->save();

        // The test kernel reuses the guard. A real request resolves it again and reloads the row.
        Auth::forgetGuards();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_form_renders(): void
    {
        $this->get('/login?view=blade')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('name="login"', false)
            ->assertSee('name="password"', false)
            ->assertSee('Lost password', false)
            ->assertSee('Register', false)
            ->assertDontSee('Auth/Login', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = []): User
    {
        return User::factory()->withPassword('secret')->create(array_merge([
            'login' => 'ada',
        ], $overrides));
    }
}
