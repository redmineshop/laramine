<?php

namespace Tests\Parity;

use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\RedminePassword;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares session sign-in, status notices, and Phase 2 tokens to the shared pin.
 *
 * Sign-in writes one session token. LDAP, two-factor, OAuth, API tokens,
 * the user directory, and account administration are compared by
 * UsersAuthGapParityTest. This class does not cover users_visibility.
 */
class UsersAuthParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_digest_session_login_and_logout(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $active = $this->active($expected);

        $row = DB::table('users')->where('id', $active['id'])->first();
        $this->assertNotNull($row);
        $this->assertSame($active['login'], $row->login);
        $this->assertSame($active['salt'], $row->salt);
        $this->assertSame($active['mail'], DB::table('email_addresses')->where('user_id', $active['id'])->value('address'));

        $passwords = new RedminePassword;
        $this->assertTrue($passwords->verify($active['password'], $row->salt, (string) $row->hashed_password));

        $user = User::query()->find($active['id']);
        $this->assertInstanceOf(User::class, $user);

        $this->post('/login', [
            'login' => $active['login'],
            'password' => $active['password'],
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()?->last_login_on);
        $session = $expected['session_on_login'];
        $this->assertIsArray($session);
        $this->assertSame($session['action'], Token::query()->value('action'));
        $this->assertSame($session['count'], Token::query()->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', [
            'login' => ' '.$active['mail'].' ',
            'password' => $active['password'],
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_placeholder_group_and_status_gates_match_the_recorded_notices(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $messages = $this->messages($expected);
        $active = $this->active($expected);

        foreach (['placeholder_user', 'group_user'] as $key) {
            $case = $expected[$key];
            $this->assertIsArray($case);
            $login = $case['login'];
            $password = $case['password'];
            $decision = $case['decision'];
            $messageKey = $case['message_key'];
            $this->assertIsString($login);
            $this->assertIsString($password);
            $this->assertIsString($decision);
            $this->assertIsString($messageKey);

            $this->from('/login')->post('/login', [
                'login' => $login,
                'password' => $password,
            ])->assertRedirect('/login')->assertSessionHasErrors([
                'login' => $messages[$messageKey],
            ]);
            $this->assertGuest();
            $this->assertSame($decision, app(CredentialChecker::class)->decide($login, $password)->value);
        }

        $gates = $expected['status_gates'];
        $this->assertIsArray($gates);
        foreach ($gates as $gate) {
            $this->assertIsArray($gate);
            $status = $gate['status'];
            $password = $gate['password'];
            $decision = $gate['decision'];
            $messageKey = $gate['message_key'];
            $this->assertIsInt($status);
            $this->assertIsString($password);
            $this->assertIsString($decision);
            $this->assertIsString($messageKey);

            if (isset($gate['self_registration'])) {
                $this->assertIsString($gate['self_registration']);
                $this->setting('self_registration', $gate['self_registration']);
            }

            User::query()->where('id', $active['id'])->update(['status' => $status]);

            $this->from('/login')->post('/login', [
                'login' => $active['login'],
                'password' => $password,
            ])->assertRedirect('/login')->assertSessionHasErrors([
                'login' => $messages[$messageKey],
            ]);
            $this->assertGuest();
            $this->assertSame(
                $decision,
                app(CredentialChecker::class)->decide($active['login'], $password)->value,
            );
        }
    }

    public function test_must_change_passwd_on_the_pin_user(): void
    {
        $this->withoutVite();
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $messages = $this->messages($expected);
        $active = $this->active($expected);
        $replacement = $expected['replacement_password'];
        $different = $expected['different_password'];
        $this->assertIsString($replacement);
        $this->assertIsString($different);

        User::query()->where('id', $active['id'])->update(['must_change_passwd' => true]);

        $this->post('/login', [
            'login' => $active['login'],
            'password' => $active['password'],
        ])->assertRedirect(route('password.edit'));
        $this->assertAuthenticated();
        $this->get('/')->assertRedirect(route('password.edit'));
        $this->get('/my/password')->assertOk()->assertSee($messages['must_change']);

        $this->post('/my/password', [
            'current_password' => $active['password'],
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ])->assertRedirect('/');

        $user = User::query()->find($active['id']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertFalse($user->must_change_passwd);
        $this->assertNotNull($user->passwd_changed_on);
        $passwords = new RedminePassword;
        $this->assertTrue($passwords->verify($replacement, $user->salt, $user->hashed_password));
        $this->assertFalse($passwords->verify($active['password'], $user->salt, $user->hashed_password));
        $this->get('/')->assertOk();

        $user->forceFill(['must_change_passwd' => true])->save();
        $this->post('/logout');
        $this->post('/login', [
            'login' => $active['login'],
            'password' => $replacement,
        ])->assertRedirect(route('password.edit'));
        $this->from('/my/password')->post('/my/password', [
            'current_password' => $replacement,
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ])->assertSessionHasErrors([
            'password' => $messages['password_must_differ'],
        ]);
        $this->post('/my/password', [
            'current_password' => $replacement,
            'password' => $different,
            'password_confirmation' => $different,
        ])->assertRedirect('/');
        $this->assertFalse($user->fresh()?->must_change_passwd);
    }

    public function test_recovery_and_register_tokens_follow_the_recorded_rules(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $messages = $this->messages($expected);
        $active = $this->active($expected);
        $tokens = $expected['tokens'];
        $registration = $expected['registration'];
        $replacement = $expected['replacement_password'];
        $this->assertIsArray($tokens);
        $this->assertIsArray($registration);
        $this->assertIsString($replacement);
        $recoveryAction = $tokens['recovery_action'];
        $registerAction = $tokens['register_action'];
        $valueLength = $tokens['value_length'];
        $this->assertIsString($recoveryAction);
        $this->assertIsString($registerAction);
        $this->assertIsInt($valueLength);

        User::query()->where('id', $active['id'])->update(['must_change_passwd' => true]);

        $sent = $this->post('/account/lost_password', [
            'mail' => $active['mail'],
        ]);
        $sent->assertSessionHas('status', $messages['recovery_sent']);

        $recovery = Token::query()->where('action', $recoveryAction)->first();
        $this->assertNotNull($recovery);
        $this->assertSame($active['id'], $recovery->user_id);
        $this->assertSame($valueLength, strlen($recovery->value));
        $this->assertSame(1, preg_match('/^[0-9a-f]{40}$/', $recovery->value));
        $sent->assertDontSee($recovery->value);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        $recovery->forceFill(['created_on' => now()->subSeconds(((int) $tokens['lifetime_seconds']) + 5)])->save();
        $this->get('/account/lost_password?token='.$recovery->value)
            ->assertRedirect(route('password.request'));
        $this->get('/account/lost_password')->assertRedirect('/');
        $this->assertNotNull(Token::query()->find($recovery->id));

        $recovery->forceFill(['created_on' => now()])->save();
        $this->get('/account/lost_password?token='.$recovery->value)
            ->assertRedirect(route('password.request'));
        $this->post('/account/lost_password/reset', [
            'password' => $replacement,
            'password_confirmation' => $replacement,
        ])->assertRedirect(route('login'))
            ->assertSessionHas('status', $messages['password_updated']);

        $this->assertNull(Token::query()->find($recovery->id));
        $reset = User::query()->find($active['id']);
        $this->assertInstanceOf(User::class, $reset);
        $this->assertFalse($reset->must_change_passwd);
        $this->assertTrue((new RedminePassword)->verify($replacement, $reset->salt, $reset->hashed_password));

        $this->setting('self_registration', (string) $registration['email_mode']);
        $login = $registration['login'];
        $mail = $registration['mail'];
        $password = $registration['password'];
        $this->assertIsString($login);
        $this->assertIsString($mail);
        $this->assertIsString($password);

        $created = $this->post('/account/register', [
            'login' => $login,
            'password' => $password,
            'password_confirmation' => $password,
            'firstname' => $registration['firstname'],
            'lastname' => $registration['lastname'],
            'mail' => $mail,
        ]);
        $created->assertRedirect(route('login'))
            ->assertSessionHas('status', $messages['registered_email']);

        $registered = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $registered);
        $this->assertSame($registration['status_before_activation'], (int) $registered->status);
        $register = Token::query()->where('user_id', $registered->id)->where('action', $registerAction)->first();
        $this->assertNotNull($register);
        $this->assertSame($valueLength, strlen($register->value));
        $created->assertDontSee($register->value);
        $this->assertGuest();

        $this->get('/account/activate?token='.$register->value)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', $messages['activated']);
        $this->assertSame($registration['status_after_activation'], (int) $registered->fresh()?->status);
        $this->assertNull(Token::query()->find($register->id));
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| P0 table and column layout \| VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/UsersAuthParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/users-auth/sign-in.json', $checklist);
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)(?!Custom fields \|)(?!Queries \|)(?!Journals and private notes \|)(?!Time entries and attachments \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/users-auth/sign-in.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{id: int, login: string, mail: string, salt: string, password: string}
     */
    private function active(array $expected): array
    {
        $active = $expected['active_user'] ?? null;
        $this->assertIsArray($active);
        $this->assertIsInt($active['id'] ?? null);
        $this->assertIsString($active['login'] ?? null);
        $this->assertIsString($active['mail'] ?? null);
        $this->assertIsString($active['salt'] ?? null);
        $this->assertIsString($active['password'] ?? null);

        return [
            'id' => $active['id'],
            'login' => $active['login'],
            'mail' => $active['mail'],
            'salt' => $active['salt'],
            'password' => $active['password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, string>
     */
    private function messages(array $expected): array
    {
        $messages = $expected['messages'] ?? null;
        $this->assertIsArray($messages);
        $typed = [];
        foreach ($messages as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
            $typed[$key] = $value;
        }

        return $typed;
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value],
        );
    }
}
