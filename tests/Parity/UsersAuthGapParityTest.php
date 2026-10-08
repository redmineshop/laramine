<?php

namespace Tests\Parity;

use App\Domain\Auth\AccountAdminService;
use App\Domain\Auth\AccountPreferences;
use App\Domain\Auth\ActionToken;
use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\Ldap\LdapAuthenticator;
use App\Domain\Auth\Ldap\LdapDirectory;
use App\Domain\Auth\Ldap\MemoryLdapDirectory;
use App\Domain\Auth\LoginDecision;
use App\Domain\Auth\Oauth\OauthProvider;
use App\Domain\Auth\Oauth\Pkce;
use App\Domain\Auth\PreferenceCodec;
use App\Domain\Auth\RedminePassword;
use App\Domain\Auth\RestAuthenticator;
use App\Domain\Auth\Totp;
use App\Domain\Auth\TwoFactorPolicy;
use App\Domain\Auth\TwoFactorService;
use App\Domain\Auth\WebSession;
use App\Domain\DomainException;
use App\Domain\PermissionDeniedException;
use App\Domain\Queries\IssueQueryRunner;
use App\Domain\Queries\QueryType;
use App\Domain\Queries\QueryValidationException;
use App\Domain\Queries\UserQueryRunner;
use App\Domain\Settings\SettingValue;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\Query;
use App\Models\Setting;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares the remaining users and authentication phases to the shared pin.
 *
 * OpenID Connect is N/A. A live LDAP directory stays open. The full REST API is
 * outside this comparison. Outbound mail and activity are separate rows.
 */
class UsersAuthGapParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ldap_onthefly_mapping_rejects_the_local_digest(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $ldap = $this->ldap($expected);
        $directory = $this->directory();
        $source = $this->ldapSource(true, 'ldap.example.test');
        $directory->add((int) $source->id, 'uid=gina,dc=example,dc=test', $ldap['password'], [
            'uid' => $ldap['login'],
            'givenName' => $ldap['firstname'],
            'sn' => $ldap['lastname'],
            'mail' => $ldap['mail'],
            'objectClass' => 'person',
        ]);
        $directory->add((int) $source->id, 'uid=half,dc=example,dc=test', $ldap['password'], [
            'uid' => 'half',
            'givenName' => 'Half',
            'sn' => 'Entry',
            'objectClass' => 'person',
        ]);

        $checker = app(CredentialChecker::class);
        $this->assertSame(LoginDecision::Accepted, $checker->decide($ldap['login'], $ldap['password']));
        $created = User::query()->where('login', $ldap['login'])->first();
        $this->assertInstanceOf(User::class, $created);
        $this->assertSame((int) $source->id, $created->auth_source_id);
        $this->assertSame($ldap['firstname'], $created->firstname);
        $this->assertSame($ldap['lastname'], $created->lastname);
        $this->assertSame(User::STATUS_ACTIVE, (int) $created->status);
        $this->assertSame(
            $ldap['mail'],
            EmailAddress::query()->where('user_id', $created->id)->where('is_default', true)->value('address'),
        );
        $passwords = new RedminePassword;
        $this->assertFalse($passwords->verify($ldap['password'], $created->salt, $created->hashed_password));
        $this->assertNull(User::query()->where('login', 'half')->first());

        $this->assertSame(
            $ldap['filter'],
            app(LdapAuthenticator::class)->filter($source, $ldap['filter_login']),
        );

        $bea = $this->user('bea');
        $sealed = $passwords->seal('secret-2');
        $bea->forceFill([
            'hashed_password' => $sealed['hashed_password'],
            'salt' => $sealed['salt'],
            'auth_source_id' => $source->id,
        ])->save();
        $directory->add((int) $source->id, 'uid=bea,dc=example,dc=test', $ldap['password'], [
            'uid' => 'bea',
            'givenName' => 'Bea',
            'sn' => 'Mapped',
            'mail' => 'bea-mapped@parity.test',
            'objectClass' => 'person',
        ]);
        $this->assertSame(LoginDecision::Password, $checker->decide('bea', 'secret-2'));
        $this->assertSame(LoginDecision::Accepted, $checker->decide('bea', $ldap['password']));
        $bea->refresh();
        $this->assertSame('Mapped', $bea->lastname);
        $this->assertFalse($passwords->verify($ldap['password'], $bea->salt, $bea->hashed_password));

        $source->forceFill(['host' => ''])->save();
        $this->assertSame(LoginDecision::ExternalAuth, $checker->decide('bea', $ldap['password']));

        $closed = $this->ldapSource(true, 'ldap.example.test');
        $closed->forceFill(['account' => 'cn=service', 'account_password' => 'nope'])->save();
        $directory->add((int) $closed->id, 'uid=nina,dc=example,dc=test', $ldap['password'], [
            'uid' => 'nina',
            'givenName' => 'Nina',
            'sn' => 'New',
            'mail' => 'nina@parity.test',
            'objectClass' => 'person',
        ]);
        $this->assertSame(LoginDecision::Unknown, $checker->decide('nina', $ldap['password']));
        $this->assertNull(User::query()->where('login', 'nina')->first());
        $this->assertFalse(class_exists('App\\Domain\\Auth\\Ldap\\PhpLdapDirectory'));
    }

    public function test_totp_backup_codes_and_required_setting(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $tokens = $this->tokens($expected);
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $service = app(TwoFactorService::class);
        $policy = app(TwoFactorPolicy::class);
        $checker = app(CredentialChecker::class);
        $now = 1_700_000_030;

        $this->setting(SettingValue::TWOFA, '0');
        $ada->forceFill(['twofa_scheme' => 'totp', 'twofa_totp_key' => 'AAAA', 'twofa_required' => true])->save();
        $this->assertFalse($policy->challengeRequired($ada->refresh()));
        $this->assertSame(LoginDecision::Accepted, $checker->decide('ada', 'secret'));

        $ada->forceFill([
            'twofa_scheme' => null,
            'twofa_totp_key' => null,
            'twofa_totp_last_used_at' => null,
            'twofa_required' => true,
        ])->save();
        $this->setting(SettingValue::TWOFA, '1');
        $this->assertTrue($policy->mustEnroll($ada->refresh()));
        $secret = $service->beginSecret();
        $code = app(Totp::class)->codeFor($secret, $now);
        $backups = $service->confirm($ada->refresh(), $secret, $code, $now);
        $this->assertCount($tokens['backup_count'], $backups);
        $this->assertFalse($policy->mustEnroll($ada->refresh()));
        $this->assertTrue($policy->challengeRequired($ada));
        $this->assertSame(LoginDecision::TwoFactor, $checker->decide('ada', 'secret'));
        $this->assertFalse($service->verify($ada->refresh(), $code, $now));
        $next = app(Totp::class)->codeFor($secret, $now + 30);
        $this->assertTrue($service->verify($ada->refresh(), $next, $now + 30));
        $this->assertFalse($service->verify($ada->refresh(), $next, $now + 30));

        foreach ($backups as $backup) {
            $this->assertSame($tokens['value_length'], strlen($backup));
            $this->assertSame(1, preg_match('/^[0-9a-f]{40}$/', $backup));
        }
        $this->assertTrue($service->verifyBackup($ada, $backups[0]));
        $this->assertFalse($service->verifyBackup($ada, $backups[0]));
        $this->assertSame(
            $tokens['backup_count'] - 1,
            Token::query()->where('user_id', $ada->id)->where('action', $tokens['backup'])->count(),
        );

        $service->disable($ada->refresh());
        $ada->forceFill(['twofa_required' => false])->save();
        $this->assertNull($ada->fresh()?->twofa_scheme);
        $this->assertSame(0, Token::query()->where('user_id', $ada->id)->where('action', $tokens['backup'])->count());

        $this->setting(SettingValue::TWOFA, '2');
        $this->assertTrue($policy->mustEnroll($admin->refresh()));
        $this->assertFalse($policy->mustEnroll($ada->refresh()));
        $this->setting(SettingValue::TWOFA, '3');
        $this->assertTrue($policy->mustEnroll($ada->refresh()));
    }

    public function test_api_key_feed_key_and_switch_user(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $tokens = $this->tokens($expected);
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $bea = $this->user('bea');
        $issuer = app(ActionToken::class);
        $api = $issuer->issueNamed($ada, Token::ACTION_API);
        $replaced = $issuer->issueNamed($ada, Token::ACTION_API);
        $feed = $issuer->issueNamed($ada, Token::ACTION_FEEDS);
        $adminKey = $issuer->issueNamed($admin, Token::ACTION_API);

        $this->assertSame($tokens['value_length'], strlen($api->value));
        $this->assertSame(1, Token::query()->where('user_id', $ada->id)->where('action', $tokens['api'])->count());
        $this->assertNull($issuer->findByValue(Token::ACTION_API, $api->value));

        $this->flushHeaders();
        $this->get('/users/current.json')->assertUnauthorized();
        $this->withHeader('X-Redmine-API-Key', $replaced->value)->get('/users/current.json')->assertUnauthorized();
        $this->flushHeaders();
        $this->setting(SettingValue::REST_API_ENABLED, '1');

        $this->get('/users/current.json')->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeader('X-Redmine-API-Key', $replaced->value)
            ->getJson('/users/current.json')
            ->assertOk()
            ->assertJsonPath('user.login', 'ada');
        $this->flushHeaders();
        $this->getJson('/users/current.json?key='.$replaced->value)
            ->assertOk()
            ->assertJsonPath('user.login', 'ada');
        $this->flushHeaders();
        $this->getJson('/users/current.json?key='.$feed->value)->assertUnauthorized();
        $this->withHeader('X-Redmine-API-Key', $feed->value)->get('/users/current.json')->assertUnauthorized();
        $this->flushHeaders();
        $this->get('/my.atom?key='.$replaced->value)->assertUnauthorized();
        $this->get('/my.atom?key='.$feed->value)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
            ->assertSee('<name>ada</name>', false);
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Basic '.base64_encode('ada:secret'))
            ->getJson('/users/current.json')
            ->assertOk()
            ->assertJsonPath('user.login', 'ada');

        $this->flushHeaders();
        $this->withHeader('X-Redmine-API-Key', $replaced->value)
            ->withHeader('X-Redmine-Switch-User', 'bea')
            ->get('/users/current.json')
            ->assertStatus(412);
        $this->flushHeaders();
        $this->withHeader('X-Redmine-API-Key', $adminKey->value)
            ->withHeader('X-Redmine-Switch-User', 'bea')
            ->getJson('/users/current.json')
            ->assertOk()
            ->assertJsonPath('user.id', (int) $bea->id);
        $this->flushHeaders();
        $this->withHeader('X-Redmine-API-Key', $adminKey->value)
            ->withHeader('X-Redmine-Switch-User', 'lars')
            ->get('/users/current.json')
            ->assertStatus(412);
        $this->flushHeaders();

        $request = Request::create('/users/current.json', 'GET');
        $request->headers->set('X-Redmine-API-Key', $replaced->value);
        $identity = app(RestAuthenticator::class)->authenticate($request);
        $this->assertSame((int) $ada->id, $identity->user?->id);
    }

    public function test_oauth_authorization_code_pkce_and_refresh(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $oauth = $this->oauth($expected);
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $verifier = str_repeat('a', $oauth['verifier_length']);
        $challenge = Pkce::s256($verifier);

        $this->actingAs($ada)->postJson('/oauth/applications', [
            'name' => 'Denied',
            'redirect_uri' => $oauth['redirect_uri'],
            'scopes' => $oauth['scope'],
        ])->assertForbidden();

        $created = $this->actingAs($admin)->postJson('/oauth/applications', [
            'name' => 'Pin client',
            'redirect_uri' => $oauth['redirect_uri'],
            'scopes' => $oauth['scope'],
            'confidential' => '1',
        ])->assertCreated();
        $uid = $created->json('uid');
        $secret = $created->json('secret');
        $this->assertIsString($uid);
        $this->assertIsString($secret);

        $denied = $this->actingAs($ada)->post('/oauth/authorize', [
            'client_id' => $uid,
            'redirect_uri' => $oauth['redirect_uri'],
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => $oauth['scope'],
            'approve' => '0',
        ]);
        $denied->assertRedirect();
        $deniedLocation = $denied->headers->get('Location');
        $this->assertIsString($deniedLocation);
        $this->assertStringContainsString('error=access_denied', $deniedLocation);

        $approved = $this->actingAs($ada)->post('/oauth/authorize', [
            'client_id' => $uid,
            'redirect_uri' => $oauth['redirect_uri'],
            'response_type' => 'code',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => $oauth['scope'],
            'approve' => '1',
        ]);
        $location = $approved->headers->get('Location');
        $this->assertIsString($location);
        $code = $this->queryValue($location, 'code');
        $this->assertSame('xyz', $this->queryValue($location, 'state'));

        $grant = DB::table('oauth_access_grants')->where('token', $code)->first();
        $this->assertNotNull($grant);
        $this->assertSame($oauth['code_ttl'], (int) $grant->expires_in);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $uid,
            'code' => $code,
            'redirect_uri' => $oauth['redirect_uri'],
            'code_verifier' => $verifier,
        ])->assertUnauthorized();

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $uid,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => $oauth['redirect_uri'],
            'code_verifier' => $verifier,
        ])->assertOk();
        $access = $token->json('access_token');
        $refresh = $token->json('refresh_token');
        $this->assertIsString($access);
        $this->assertIsString($refresh);
        $this->assertSame('Bearer', $token->json('token_type'));
        $this->assertSame($oauth['token_ttl'], $token->json('expires_in'));
        $this->assertSame($oauth['scope'], $token->json('scope'));
        $this->assertArrayNotHasKey('id_token', $token->json());
        $this->get('/.well-known/openid-configuration')->assertNotFound();

        $this->setting(SettingValue::REST_API_ENABLED, '1');
        $this->withHeader('Authorization', 'Bearer '.$access)
            ->getJson('/users/current.json')
            ->assertOk()
            ->assertJsonPath('user.login', 'ada');

        $rotated = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $uid,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
        ])->assertOk();
        $nextAccess = $rotated->json('access_token');
        $this->assertIsString($nextAccess);
        $this->assertNotSame($access, $nextAccess);
        $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $uid,
            'client_secret' => $secret,
            'refresh_token' => $refresh,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNull(app(OauthProvider::class)->resourceOwner($nextAccess));

        $public = app(OauthProvider::class)->registerApplication($admin, 'Public', $oauth['redirect_uri'], $oauth['scope'], false);
        $publicUrl = app(OauthProvider::class)->authorize($ada, [
            'client_id' => $public['application']->uid,
            'redirect_uri' => $oauth['redirect_uri'],
            'response_type' => 'code',
            'scope' => $oauth['scope'],
        ], true);
        $this->assertStringContainsString('error=invalid_request', $publicUrl);
    }

    public function test_user_directory_order_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $directory = $this->directoryExpectation($expected);
        $runner = app(UserQueryRunner::class);
        $ada = $this->user('ada');
        $admin = $this->user('admin');
        $erin = $this->user('erin');

        $this->assertSame($directory['ada'], $this->logins($runner->preview($ada, [])));
        $this->assertSame($directory['admin'], $this->logins($runner->preview($admin, [])));
        $this->assertSame($directory['erin'], $this->logins($runner->preview($erin, [])));
        $this->assertSame($directory['status_locked'], $this->logins($runner->preview($admin, [
            'status' => ['operator' => '=', 'values' => ['3']],
        ])));
        $this->assertSame(['ada'], $this->logins($runner->preview($admin, [
            'mail' => ['operator' => '~', 'values' => ['ada@']],
        ])));

        $saved = Query::query()->create([
            'name' => 'Locked accounts',
            'user_id' => $ada->id,
            'visibility' => 0,
            'type' => QueryType::USER,
            'filters' => ['status' => ['operator' => '=', 'values' => ['3']]],
            'sort_criteria' => [],
        ]);
        $this->assertSame($directory['status_locked'], $this->logins($runner->execute($admin, $saved)));

        try {
            app(IssueQueryRunner::class)->execute($ada, $saved);
            $this->fail('IssueQuery runner accepted a UserQuery.');
        } catch (QueryValidationException $exception) {
            $this->assertStringContainsString('Only IssueQuery', $exception->getMessage());
        }

        $this->post('/login', ['login' => 'ada', 'password' => 'secret'])->assertRedirect('/');
        $page = $this->get('/users');
        $page->assertOk();
        $page->assertSeeInOrder($directory['ada']);
        $page->assertDontSee('lars');
        $page->assertDontSee('cleo');
    }

    public function test_admin_lock_unlock_activate_delete_and_preferences(): void
    {
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $adminCase = $this->admin($expected);
        $preferences = $this->preferences($expected);
        $admin = $this->user('admin');
        $ada = $this->user('ada');
        $accounts = app(AccountAdminService::class);

        try {
            $accounts->lock($ada, $this->user($adminCase['lock_login']));
            $this->fail('A non-admin locked an account.');
        } catch (PermissionDeniedException) {
            $this->assertSame(User::STATUS_ACTIVE, (int) $this->user($adminCase['lock_login'])->status);
        }

        $lockedSubject = $this->user($adminCase['lock_login']);
        $sealed = (new RedminePassword)->seal('secret-2');
        $lockedSubject->forceFill([
            'hashed_password' => $sealed['hashed_password'],
            'salt' => $sealed['salt'],
        ])->save();
        $locked = $accounts->lock($admin, $lockedSubject);
        $this->assertSame($adminCase['locked_status'], (int) $locked->status);
        $this->assertSame(LoginDecision::Locked, app(CredentialChecker::class)->decide($adminCase['lock_login'], 'secret-2'));
        try {
            $accounts->lock($admin, $admin);
            $this->fail('An administrator locked their own account.');
        } catch (DomainException) {
            $this->assertSame(User::STATUS_ACTIVE, (int) $admin->fresh()?->status);
        }
        $this->assertSame($adminCase['active_status'], (int) $accounts->unlock($admin, $locked)->status);

        $pending = $this->user($adminCase['activate_login']);
        $pending->forceFill(['status' => User::STATUS_REGISTERED])->save();
        $this->assertSame($adminCase['active_status'], (int) $accounts->activate($admin, $pending->refresh())->status);

        $group = $this->user($adminCase['group_name']);
        $member = $this->user($adminCase['group_login']);
        $accounts->addToGroup($admin, $member, $group);
        $this->assertTrue(DB::table('groups_users')->where('group_id', $group->id)->where('user_id', $member->id)->exists());
        $accounts->removeFromGroup($admin, $member, $group);
        $this->assertFalse(DB::table('groups_users')->where('group_id', $group->id)->where('user_id', $member->id)->exists());

        $subject = $this->user($adminCase['delete_login']);
        $issueIds = DB::table('issues')->where('author_id', $subject->id)->pluck('id')->all();
        $this->assertNotEmpty($issueIds);
        try {
            $accounts->delete($admin, $subject);
            $this->fail('Delete reassigned authorship without an AnonymousUser row.');
        } catch (DomainException) {
            $this->assertNotNull(User::query()->find($subject->id));
        }

        $anonymous = new User;
        $anonymous->forceFill([
            'login' => '',
            'hashed_password' => RedminePassword::PLACEHOLDER_HASH,
            'firstname' => '',
            'lastname' => 'Anonymous',
            'admin' => false,
            'status' => User::STATUS_ANONYMOUS,
            'type' => User::TYPE_ANONYMOUS,
            'language' => '',
            'mail_notification' => '',
            'must_change_passwd' => false,
        ])->save();
        $accounts->delete($admin, $subject->refresh());
        $this->assertNull(User::query()->find($subject->id));
        $this->assertSame(
            count($issueIds),
            DB::table('issues')->where('author_id', $anonymous->id)->whereIn('id', $issueIds)->count(),
        );
        $this->assertSame(0, DB::table('email_addresses')->where('user_id', $subject->id)->count());

        $preference = app(AccountPreferences::class)->update($ada, $ada, [
            'mail_notification' => $preferences['mail_notification'],
            'hide_mail' => $preferences['hide_mail'],
            'time_zone' => 'UTC',
            'others' => ['comments_sorting' => $preferences['comments_sorting']],
        ]);
        $this->assertSame($preferences['mail_notification'], $ada->fresh()?->mail_notification);
        $this->assertSame($preferences['hide_mail'], $preference->hide_mail);
        $this->assertSame(
            ['comments_sorting' => $preferences['comments_sorting']],
            (new PreferenceCodec)->decode($preference->others),
        );
        UserPreference::query()->where('user_id', $ada->id)->update(['others' => "---\n:comments_sorting: asc\n"]);
        $stored = UserPreference::query()->where('user_id', $ada->id)->value('others');
        $this->assertIsString($stored);
        $this->assertSame([], (new PreferenceCodec)->decode($stored));

        $this->actingAs($ada)->get('/users/new')->assertForbidden();
        $this->actingAs($admin)->post('/users', [
            'login' => 'nina',
            'firstname' => 'Nina',
            'lastname' => 'New',
            'mail' => 'nina@parity.test',
            'password' => 'secret-2',
            'password_confirmation' => 'secret-2',
            'mail_notification' => 'only_my_events',
        ])->assertRedirect();
        $nina = User::query()->where('login', 'nina')->first();
        $this->assertInstanceOf(User::class, $nina);
        $this->assertTrue((new RedminePassword)->verify('secret-2', $nina->salt, $nina->hashed_password));
    }

    public function test_session_cap_autologin_and_timeout(): void
    {
        $this->withoutVite();
        Redmine701Fixture::load();
        $expected = $this->expectation();
        $tokens = $this->tokens($expected);
        $ada = $this->user('ada');
        $issuer = app(ActionToken::class);
        $first = $issuer->issueNamed($ada, Token::ACTION_SESSION);
        for ($index = 0; $index < $tokens['session_cap']; $index++) {
            $issuer->issueNamed($ada, Token::ACTION_SESSION);
        }
        $this->assertSame($tokens['session_cap'], Token::query()->where('user_id', $ada->id)->where('action', $tokens['session'])->count());
        $this->assertNull(Token::query()->find($first->id));

        $this->setting(SettingValue::AUTOLOGIN, '0');
        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
            'autologin' => '1',
        ])->assertRedirect('/');
        $this->assertSame(0, Token::query()->where('action', $tokens['autologin'])->count());
        $this->post('/logout');

        $this->setting(SettingValue::AUTOLOGIN, (string) $tokens['autologin_days']);
        $this->setting(SettingValue::SESSION_TIMEOUT, '1');
        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
            'autologin' => '1',
        ])->assertRedirect('/');
        $cookie = Token::query()->where('user_id', $ada->id)->where('action', $tokens['autologin'])->value('value');
        $this->assertIsString($cookie);
        $this->assertSame($tokens['value_length'], strlen($cookie));

        $this->travel(2)->minutes();
        $this->withUnencryptedCookie(WebSession::AUTOLOGIN_COOKIE, $cookie)
            ->get('/')
            ->assertOk();
        $this->assertAuthenticatedAs($ada);
        $this->assertGreaterThanOrEqual(1, Token::query()->where('user_id', $ada->id)->where('action', $tokens['session'])->count());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, Token::query()->where('action', $tokens['autologin'])->count());

        $this->post('/login', ['login' => 'ada', 'password' => 'secret'])->assertRedirect('/');
        $this->travel(2)->minutes();
        $this->get('/')->assertOk();
        $this->assertGuest();
    }

    public function test_checklist_subrows_cite_this_comparison_and_the_holes(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $evidence = 'tests/Parity/UsersAuthGapParityTest.php';
        $expectation = 'tests/Parity/fixtures/redmine-7.0.1/expectations/users-auth/gap.json';
        foreach ([
            'Users and authentication — LDAP',
            'Users and authentication — two-factor',
            'Users and authentication — OAuth',
            'Users and authentication — API and feed tokens',
            'Users and authentication — user directory',
            'Users and authentication — account administration',
            'Users and authentication — preferences',
            'Users and authentication — session and autologin',
        ] as $row) {
            $this->assertMatchesRegularExpression('/^\| '.preg_quote($row, '/').' \| VERIFIED \|/m', $checklist);
        }
        $this->assertMatchesRegularExpression('/^\| Users and authentication — OpenID Connect \| N\/A \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — full REST API \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| NOT VERIFIED \|/m', $checklist);
        $this->assertStringContainsString($evidence, $checklist);
        $this->assertStringContainsString($expectation, $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertDoesNotMatchRegularExpression(
            '/^\| (?!P0 table and column layout \|)(?!Users and authentication)(?!Identity, membership, and permissions \|)(?!Projects and issue nested sets \|)(?!Workflows \|)(?!Custom fields \|)(?!Queries \|)(?!Journals and private notes \|)(?!Time entries and attachments \|)(?!Activity \|)(?!Activity for news, documents, and files \|)(?!Activity for wiki and messages \|)(?!News, documents, and files \|)(?!Notifications for news, documents, and files \|)(?!Notifications for messages and wiki \|)(?!Wiki \|)(?!Boards and forums \|)(?!Calendar and Gantt \|)(?!Textile and Markdown rendering \|)(?!Wiki HTTP \|)(?!Boards and forums HTTP \|)[^|\n]+\| VERIFIED \|/m',
            $checklist,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/users-auth/gap.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{login: string, password: string, firstname: string, lastname: string, mail: string, filter_login: string, filter: string}
     */
    private function ldap(array $expected): array
    {
        $ldap = $expected['ldap'] ?? null;
        $this->assertIsArray($ldap);
        foreach (['login', 'password', 'firstname', 'lastname', 'mail', 'filter_login', 'filter'] as $key) {
            $this->assertIsString($ldap[$key] ?? null);
        }

        return [
            'login' => $ldap['login'],
            'password' => $ldap['password'],
            'firstname' => $ldap['firstname'],
            'lastname' => $ldap['lastname'],
            'mail' => $ldap['mail'],
            'filter_login' => $ldap['filter_login'],
            'filter' => $ldap['filter'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{api: string, feeds: string, session: string, autologin: string, backup: string, value_length: int, session_cap: int, backup_count: int, autologin_days: int}
     */
    private function tokens(array $expected): array
    {
        $tokens = $expected['tokens'] ?? null;
        $this->assertIsArray($tokens);
        foreach (['api', 'feeds', 'session', 'autologin', 'backup'] as $key) {
            $this->assertIsString($tokens[$key] ?? null);
        }
        foreach (['value_length', 'session_cap', 'backup_count', 'autologin_days'] as $key) {
            $this->assertIsInt($tokens[$key] ?? null);
        }

        return [
            'api' => $tokens['api'],
            'feeds' => $tokens['feeds'],
            'session' => $tokens['session'],
            'autologin' => $tokens['autologin'],
            'backup' => $tokens['backup'],
            'value_length' => $tokens['value_length'],
            'session_cap' => $tokens['session_cap'],
            'backup_count' => $tokens['backup_count'],
            'autologin_days' => $tokens['autologin_days'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{redirect_uri: string, scope: string, code_ttl: int, token_ttl: int, verifier_length: int}
     */
    private function oauth(array $expected): array
    {
        $oauth = $expected['oauth'] ?? null;
        $this->assertIsArray($oauth);
        $this->assertIsString($oauth['redirect_uri'] ?? null);
        $this->assertIsString($oauth['scope'] ?? null);
        $this->assertIsInt($oauth['code_ttl'] ?? null);
        $this->assertIsInt($oauth['token_ttl'] ?? null);
        $this->assertIsInt($oauth['verifier_length'] ?? null);

        return [
            'redirect_uri' => $oauth['redirect_uri'],
            'scope' => $oauth['scope'],
            'code_ttl' => $oauth['code_ttl'],
            'token_ttl' => $oauth['token_ttl'],
            'verifier_length' => $oauth['verifier_length'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{ada: list<string>, admin: list<string>, erin: list<string>, status_locked: list<string>}
     */
    private function directoryExpectation(array $expected): array
    {
        $directory = $expected['directory'] ?? null;
        $this->assertIsArray($directory);
        $typed = [];
        foreach (['ada', 'admin', 'erin', 'status_locked'] as $key) {
            $logins = $directory[$key] ?? null;
            $this->assertIsArray($logins);
            $names = [];
            foreach ($logins as $login) {
                $this->assertIsString($login);
                $names[] = $login;
            }
            $typed[$key] = $names;
        }

        return [
            'ada' => $typed['ada'],
            'admin' => $typed['admin'],
            'erin' => $typed['erin'],
            'status_locked' => $typed['status_locked'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{comments_sorting: string, hide_mail: bool, mail_notification: string}
     */
    private function preferences(array $expected): array
    {
        $preferences = $expected['preferences'] ?? null;
        $this->assertIsArray($preferences);
        $this->assertIsString($preferences['comments_sorting'] ?? null);
        $this->assertIsBool($preferences['hide_mail'] ?? null);
        $this->assertIsString($preferences['mail_notification'] ?? null);

        return [
            'comments_sorting' => $preferences['comments_sorting'],
            'hide_mail' => $preferences['hide_mail'],
            'mail_notification' => $preferences['mail_notification'],
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{lock_login: string, activate_login: string, delete_login: string, group_login: string, group_name: string, locked_status: int, active_status: int, registered_status: int}
     */
    private function admin(array $expected): array
    {
        $admin = $expected['admin'] ?? null;
        $this->assertIsArray($admin);
        foreach (['lock_login', 'activate_login', 'delete_login', 'group_login', 'group_name'] as $key) {
            $this->assertIsString($admin[$key] ?? null);
        }
        foreach (['locked_status', 'active_status', 'registered_status'] as $key) {
            $this->assertIsInt($admin[$key] ?? null);
        }

        return [
            'lock_login' => $admin['lock_login'],
            'activate_login' => $admin['activate_login'],
            'delete_login' => $admin['delete_login'],
            'group_login' => $admin['group_login'],
            'group_name' => $admin['group_name'],
            'locked_status' => $admin['locked_status'],
            'active_status' => $admin['active_status'],
            'registered_status' => $admin['registered_status'],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function logins(array $ids): array
    {
        $rows = User::query()->whereIn('id', $ids)->pluck('login', 'id');
        $logins = [];
        foreach ($ids as $id) {
            $login = $rows[$id] ?? null;
            $this->assertIsString($login);
            $logins[] = $login;
        }

        return $logins;
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function directory(): MemoryLdapDirectory
    {
        $directory = app(LdapDirectory::class);
        $this->assertInstanceOf(MemoryLdapDirectory::class, $directory);

        return $directory;
    }

    private function ldapSource(bool $onthefly, string $host): AuthSource
    {
        return AuthSource::query()->create([
            'type' => AuthSource::TYPE_LDAP,
            'name' => 'Parity directory',
            'host' => $host,
            'port' => 389,
            'base_dn' => 'dc=example,dc=test',
            'attr_login' => 'uid',
            'attr_firstname' => 'givenName',
            'attr_lastname' => 'sn',
            'attr_mail' => 'mail',
            'filter' => 'objectClass=person',
            'onthefly_register' => $onthefly,
            'account' => '',
            'account_password' => '',
            'tls' => false,
        ]);
    }

    private function setting(string $name, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['name' => $name],
            ['value' => $value],
        );
    }

    private function queryValue(string $url, string $key): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $params);
        $value = $params[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }
}
