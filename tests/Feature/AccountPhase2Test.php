<?php

namespace Tests\Feature;

use App\Domain\Auth\AccountNotice;
use App\Domain\Auth\AccountValidationException;
use App\Domain\Auth\PasswordChangeService;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountPhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_must_change_passwd_blocks_the_app_until_the_password_changes(): void
    {
        $this->withoutVite();
        $user = $this->account(['must_change_passwd' => true]);

        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertRedirect(route('password.edit'));

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertRedirect(route('password.edit'));
        $this->get('/my/password')
            ->assertOk()
            ->assertSee(AccountNotice::MUST_CHANGE);

        $this->from('/my/password')->post('/my/password', [
            'current_password' => 'secret',
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ])->assertRedirect('/my/password')->assertSessionHasErrors('password');

        $this->post('/my/password', [
            'current_password' => 'secret',
            'password' => 'secret-2',
            'password_confirmation' => 'secret-2',
        ])->assertRedirect('/');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->must_change_passwd);
        $this->assertNotNull($fresh->passwd_changed_on);
        $this->get('/')->assertOk();
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_same_password_is_rejected_only_while_a_change_is_required(): void
    {
        $user = $this->account([
            'must_change_passwd' => true,
        ], 'secret-2');

        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret-2',
        ]);

        $this->from('/my/password')->post('/my/password', [
            'current_password' => 'secret-2',
            'password' => 'secret-2',
            'password_confirmation' => 'secret-2',
        ])->assertSessionHasErrors([
            'password' => AccountNotice::PASSWORD_MUST_DIFFER,
        ]);

        $this->assertTrue($user->fresh()?->must_change_passwd);
    }

    public function test_json_login_reports_the_flag_and_later_json_is_forbidden(): void
    {
        $this->account(['must_change_passwd' => true]);

        $this->postJson('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertOk()->assertJsonPath('must_change_passwd', true);

        $this->getJson('/')->assertForbidden()->assertJsonPath('must_change_passwd', true);
    }

    public function test_inertia_sign_in_leaves_for_the_password_form(): void
    {
        $this->account(['must_change_passwd' => true]);

        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ], [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertStatus(409)->assertHeader('X-Inertia-Location', route('password.edit'));
    }

    public function test_email_activation_notice_offers_another_register_token(): void
    {
        $this->setting('self_registration', '1');
        $user = $this->account(['status' => User::STATUS_REGISTERED]);

        $this->from('/login')->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ])->assertSessionHasErrors([
            'login' => AccountNotice::NOT_ACTIVATED,
        ]);

        $this->assertGuest();
        $this->post('/account/activation_email')->assertRedirect(route('login'));

        $token = Token::query()->where('action', Token::ACTION_REGISTER)->first();
        $this->assertNotNull($token);
        $this->assertSame($user->id, $token->user_id);
        $this->assertSame(40, strlen($token->value));

        $first = $token->value;
        $this->post('/account/activation_email')->assertRedirect(route('login'));
        $this->assertSame(1, Token::query()->where('action', Token::ACTION_REGISTER)->count());
        $this->assertNotSame($first, Token::query()->value('value'));
    }

    public function test_recovery_resets_the_pin_style_digest_and_clears_the_flag(): void
    {
        $user = $this->account(['must_change_passwd' => true]);
        EmailAddress::query()->create([
            'user_id' => $user->id,
            'address' => 'Ada@Example.test',
            'is_default' => true,
            'notify' => true,
        ]);

        $this->post('/account/lost_password', [
            'mail' => ' ada@example.test ',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHas('status', AccountNotice::RECOVERY_SENT);

        $token = Token::query()->where('action', Token::ACTION_RECOVERY)->first();
        $this->assertNotNull($token);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->get('/account/lost_password?token='.$token->value)
            ->assertRedirect(route('password.request'));
        $this->get('/account/lost_password')->assertOk()->assertSee('New password');

        $this->post('/account/lost_password/reset', [
            'password' => 'secret',
            'password_confirmation' => 'secret',
        ])->assertSessionHasErrors('password');
        $this->assertNotNull(Token::query()->find($token->id));

        $this->post('/account/lost_password/reset', [
            'password' => 'secret-2',
            'password_confirmation' => 'secret-2',
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', AccountNotice::PASSWORD_UPDATED);

        $this->assertNull(Token::query()->find($token->id));
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->must_change_passwd);
        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret-2',
        ])->assertRedirect('/');
    }

    public function test_expired_recovery_token_is_rejected(): void
    {
        $user = $this->account();
        EmailAddress::query()->create([
            'user_id' => $user->id,
            'address' => 'ada@example.test',
            'is_default' => true,
            'notify' => true,
        ]);
        $this->post('/account/lost_password', ['mail' => 'ada@example.test']);
        $token = Token::query()->first();
        $this->assertNotNull($token);
        $token->forceFill(['created_on' => now()->subDays(2)])->save();

        $this->get('/account/lost_password?token='.$token->value)
            ->assertRedirect(route('password.request'));
        $this->get('/account/lost_password')->assertRedirect('/');
        $this->assertSame($user->hashed_password, $user->fresh()?->hashed_password);
    }

    public function test_unknown_or_ambiguous_or_external_mail_stores_no_token(): void
    {
        $source = AuthSource::query()->create([
            'name' => 'Directory',
            'type' => 'AuthSourceLdap',
        ]);
        $external = $this->account([
            'login' => 'ldap-user',
            'auth_source_id' => $source->id,
        ]);
        EmailAddress::query()->create([
            'user_id' => $external->id,
            'address' => 'ldap@example.test',
            'is_default' => true,
            'notify' => true,
        ]);
        $left = $this->account(['login' => 'left']);
        $right = $this->account(['login' => 'right']);
        EmailAddress::query()->create([
            'user_id' => $left->id,
            'address' => 'shared@example.test',
            'is_default' => true,
            'notify' => true,
        ]);
        EmailAddress::query()->create([
            'user_id' => $right->id,
            'address' => 'shared@example.test',
            'is_default' => false,
            'notify' => true,
        ]);

        foreach (['missing@example.test', 'ldap@example.test', 'shared@example.test'] as $mail) {
            $this->post('/account/lost_password', ['mail' => $mail])
                ->assertSessionHas('status', AccountNotice::RECOVERY_SENT);
        }

        $this->assertSame(0, Token::query()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_lost_password_and_registration_can_be_turned_off(): void
    {
        $this->setting('lost_password', '0');
        $this->setting('self_registration', '0');

        $this->get('/account/lost_password')->assertRedirect('/');
        $this->post('/account/lost_password', ['mail' => 'ada@example.test'])->assertRedirect('/');
        $this->get('/account/register')->assertRedirect('/');
        $this->post('/account/register', $this->registrationPayload())->assertRedirect('/');
        $this->assertSame(0, User::query()->where('login', 'newcomer')->count());
    }

    public function test_registration_modes_and_activation(): void
    {
        $this->setting('self_registration', '1');
        $this->post('/account/register', $this->registrationPayload())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', AccountNotice::REGISTERED_EMAIL);

        $user = User::query()->where('login', 'newcomer')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::STATUS_REGISTERED, (int) $user->status);
        $this->assertSame('only_my_events', $user->mail_notification);
        $this->assertNotNull($user->passwd_changed_on);
        $this->assertTrue(UserPreference::query()->where('user_id', $user->id)->where('hide_mail', true)->exists());
        $token = Token::query()->where('user_id', $user->id)->where('action', Token::ACTION_REGISTER)->first();
        $this->assertNotNull($token);
        $this->assertGuest();

        $this->get('/account/activate?token=not-a-token')->assertRedirect('/');
        $token->forceFill(['created_on' => now()->subDay()->subSecond()])->save();
        $this->get('/account/activate?token='.$token->value)->assertRedirect('/');
        $this->assertSame(User::STATUS_REGISTERED, (int) $user->fresh()?->status);

        $token->forceFill(['created_on' => now()])->save();
        $this->get('/account/activate?token='.$token->value)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', AccountNotice::ACTIVATED);
        $this->assertSame(User::STATUS_ACTIVE, (int) $user->fresh()?->status);
        $this->assertNull(Token::query()->find($token->id));
        $this->assertGuest();

        $this->setting('self_registration', '2');
        $this->post('/account/register', $this->registrationPayload('manual', 'manual@example.test'))
            ->assertSessionHas('status', AccountNotice::PENDING);
        $manual = User::query()->where('login', 'manual')->first();
        $this->assertNotNull($manual);
        $this->assertSame(User::STATUS_REGISTERED, (int) $manual->status);
        $this->assertSame(0, Token::query()->where('user_id', $manual->id)->count());

        $this->setting('self_registration', '3');
        $this->post('/account/register', $this->registrationPayload('auto', 'auto@example.test'))
            ->assertRedirect('/');
        $auto = User::query()->where('login', 'auto')->first();
        $this->assertNotNull($auto);
        $this->assertSame(User::STATUS_ACTIVE, (int) $auto->status);
        $this->assertAuthenticatedAs($auto);
        $this->assertNotNull($auto->last_login_on);
    }

    public function test_registered_lost_password_reissues_a_register_token(): void
    {
        $this->setting('self_registration', '1');
        $user = $this->account(['status' => User::STATUS_REGISTERED]);
        EmailAddress::query()->create([
            'user_id' => $user->id,
            'address' => 'ada@example.test',
            'is_default' => true,
            'notify' => true,
        ]);

        $this->post('/account/lost_password', ['mail' => 'ada@example.test'])
            ->assertSessionHas('status', AccountNotice::RECOVERY_SENT);

        $this->assertSame(1, Token::query()->where('action', Token::ACTION_REGISTER)->count());
        $this->assertSame(0, Token::query()->where('action', Token::ACTION_RECOVERY)->count());
    }

    public function test_duplicate_login_and_short_password_are_rejected(): void
    {
        $this->account();
        $this->setting('password_required_char_classes', '3');

        $this->from('/account/register')->post('/account/register', $this->registrationPayload('Ada', 'ada@example.test', 'short'))
            ->assertRedirect('/account/register')
            ->assertSessionHasErrors(['login', 'password']);

        $this->assertSame(1, User::query()->count());
    }

    public function test_external_account_cannot_change_a_local_password(): void
    {
        $source = AuthSource::query()->create([
            'name' => 'Directory',
            'type' => 'AuthSourceLdap',
        ]);
        $user = $this->account([
            'login' => 'ldap-user',
            'auth_source_id' => $source->id,
        ]);

        try {
            app(PasswordChangeService::class)->change($user, 'secret', 'secret-2', 'secret-2');
            $this->fail('An external account must not change the local digest.');
        } catch (AccountValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors);
        }

        $this->assertSame($user->hashed_password, $user->fresh()?->hashed_password);
    }

    public function test_login_page_hides_closed_flows(): void
    {
        $this->withoutVite();
        $this->setting('lost_password', '0');
        $this->setting('self_registration', '0');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('lostPasswordUrl', null)
                ->where('registerUrl', null)
            );

        $this->get('/login?view=blade')
            ->assertOk()
            ->assertDontSee('Lost password')
            ->assertDontSee('Register');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function account(array $overrides = [], string $password = 'secret'): User
    {
        return User::factory()->withPassword($password)->create(array_merge([
            'login' => 'ada',
        ], $overrides));
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value],
        );
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(
        string $login = 'newcomer',
        string $mail = 'newcomer@example.test',
        string $password = 'secret-2',
    ): array {
        return [
            'login' => $login,
            'password' => $password,
            'password_confirmation' => $password,
            'firstname' => 'New',
            'lastname' => 'Comer',
            'mail' => $mail,
        ];
    }
}
