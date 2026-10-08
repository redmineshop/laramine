<?php

namespace Tests\Parity;

use App\Domain\Auth\CredentialChecker;
use App\Domain\Auth\Ldap\ExtLdapDirectory;
use App\Domain\Auth\Ldap\LdapAuthenticator;
use App\Domain\Auth\Ldap\LdapBindException;
use App\Domain\Auth\Ldap\LdapDirectory;
use App\Domain\Auth\Ldap\LdapDn;
use App\Domain\Auth\Ldap\LdapEntry;
use App\Domain\Auth\Ldap\LdapMode;
use App\Domain\Auth\Ldap\LdapTimeout;
use App\Domain\Auth\Ldap\LdapTimeoutException;
use App\Domain\Auth\RedminePassword;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Parity\Support\LiveLdapSeed;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Compares LDAP sign-in to a live directory and the 7.0.1 auth_sources columns.
 *
 * The in-memory adapter stays the testing default. This test binds
 * ExtLdapDirectory for one comparison. STARTTLS is not a 7.0.1 mode.
 */
class LiveLdapParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_directory_matches_the_pin(): void
    {
        Redmine701Fixture::load();
        LiveLdapSeed::apply();
        $this->app->instance(LdapDirectory::class, new ExtLdapDirectory);
        $expected = $this->expectation();
        $directory = $this->section($expected, 'directory');
        $this->assertDirectoryPin($directory);

        $modes = [];
        foreach (LdapMode::cases() as $mode) {
            $modes[] = $mode->value;
        }
        $this->assertSame($expected['modes'], $modes);
        $this->assertSame('not_exposed', $expected['start_tls']);
        $this->assertModes();
        $this->assertTimeouts($this->section($expected, 'timeout'));
        $this->assertSame(
            $this->stringField($this->section($expected, 'dn'), 'substituted'),
            LdapDn::substituteLogin(
                $this->stringField($this->section($expected, 'dn'), 'account'),
                $this->stringField($this->section($expected, 'dn'), 'login'),
            ),
        );
        $this->assertSchemaHasNoStartTls();

        $password = $this->stringField($directory, 'password');
        $local = $this->stringField($expected, 'local_password');
        $accounts = $this->section($expected, 'accounts');
        $decisions = $this->section($expected, 'decisions');
        $onthefly = $this->section($expected, 'onthefly');
        $dn = $this->section($expected, 'dn');
        $anonymous = $this->source('Anonymous onthefly', [
            'filter' => $this->stringField($this->section($expected, 'auth_filter'), 'extra'),
            'attr_firstname' => 'displayName',
            'onthefly_register' => true,
            'timeout' => $this->intField($this->section($expected, 'timeout'), 'stored'),
            'verify_peer' => true,
        ]);
        $loginSource = $this->source('Login template', [
            'account' => $this->stringField($dn, 'live_account'),
            'filter' => '(departmentNumber=dollar)',
            'onthefly_register' => true,
        ]);
        $bad = $this->source('Bad service', [
            'account' => $this->stringField($directory, 'admin_dn'),
            'account_password' => 'not-the-password',
            'filter' => '(departmentNumber=secret)',
            'onthefly_register' => true,
        ]);
        $service = $this->source('Service account', [
            'account' => $this->stringField($directory, 'admin_dn'),
            'account_password' => $this->stringField($directory, 'admin_password'),
            'filter' => '',
            'onthefly_register' => false,
        ]);
        $this->assertTrue($anonymous->id < $loginSource->id);
        $this->assertTrue($loginSource->id < $bad->id);
        $this->assertTrue($bad->id < $service->id);

        $checker = app(CredentialChecker::class);
        $authenticator = app(LdapAuthenticator::class);
        $passwords = app(RedminePassword::class);
        $client = app(LdapDirectory::class);
        $this->assertInstanceOf(ExtLdapDirectory::class, $client);

        $authFilter = $this->section($expected, 'auth_filter');
        $this->assertSame(
            $this->stringField($authFilter, 'filter'),
            $authenticator->filter($anonymous, $this->stringField($authFilter, 'login')),
        );
        $star = $client->search($anonymous, $authenticator->filter($anonymous, $this->stringField($authFilter, 'login')));
        $this->assertCount(1, $star);

        $userFilter = $this->section($expected, 'user_filter');
        $this->assertSame(
            $this->stringField($userFilter, 'filter'),
            $authenticator->userFilter($service, $this->stringField($userFilter, 'query')),
        );

        $bea = $this->user('bea');
        $lars = $this->user('lars');
        $ada = $this->user('ada');
        $this->assertSame($this->stringField($decisions, 'bea_before'), $bea->lastname);
        $this->assertSame($this->stringField($decisions, 'lars_before'), $lars->lastname);
        $this->assertSame($this->intField($accounts, 'locked_status'), (int) $lars->status);
        $this->assertNull($ada->auth_source_id);
        $bea->forceFill(['auth_source_id' => $service->id])->save();
        $lars->forceFill(['auth_source_id' => $service->id])->save();
        $this->localUser('pending', $this->intField($accounts, 'registered_status'), (int) $service->id, $this->stringField($accounts, 'stored_lastname'), $passwords);
        $this->localUser('idle', $this->intField($accounts, 'inactive_status'), (int) $service->id, $this->stringField($accounts, 'stored_lastname'), $passwords);
        $comma = $this->localUser(
            $this->stringField($dn, 'login'),
            $this->intField($accounts, 'active_status'),
            (int) $loginSource->id,
            $this->stringField($accounts, 'stored_lastname'),
            $passwords,
        );

        $rejected = $expected['rejected'] ?? null;
        $this->assertIsArray($rejected);
        foreach ($rejected as $login) {
            $this->assertIsString($login);
            $this->assertSame('unknown', $checker->decide($login, $password)->value);
            $this->assertNull(User::query()->where('login', $login)->first());
        }

        $this->assertSame($this->stringField($onthefly, 'decision'), $checker->decide($this->stringField($onthefly, 'login'), $password)->value);
        $gina = $this->user($this->stringField($onthefly, 'login'));
        $this->assertSame($this->stringField($onthefly, 'firstname'), $gina->firstname);
        $this->assertSame($this->stringField($onthefly, 'lastname'), $gina->lastname);
        $this->assertSame($this->intField($onthefly, 'status'), (int) $gina->status);
        $this->assertSame((int) $anonymous->id, (int) $gina->auth_source_id);
        $this->assertSame($this->stringField($onthefly, 'mail'), $this->mail($gina));
        $this->assertFalse($passwords->verify($password, $gina->salt, $gina->hashed_password));
        $this->assertSame($this->stringField($decisions, 'gina_wrong'), $checker->decide($this->stringField($onthefly, 'login'), $local)->value);

        $this->assertSame($this->stringField($decisions, 'nobody'), $checker->decide('nobody', $password)->value);
        $this->assertSame($this->stringField($decisions, 'omar'), $checker->decide('omar', $password)->value);
        $this->assertNull(User::query()->where('login', 'omar')->first());
        $this->assertSame($this->stringField($decisions, 'nina'), $checker->decide('nina', $password)->value);
        $this->assertNull(User::query()->where('login', 'nina')->first());

        $this->assertSame($this->stringField($decisions, 'ada_local'), $checker->decide('ada', $local)->value);
        $this->assertSame($this->stringField($decisions, 'ada_directory'), $checker->decide('ada', $password)->value);
        $this->assertSame($this->stringField($decisions, 'ada_lastname'), $this->user('ada')->lastname);
        $this->assertNull($this->user('ada')->auth_source_id);

        $this->assertSame('password', $checker->decide('bea', $local)->value);
        $this->assertSame($this->stringField($decisions, 'bea_before'), $this->user('bea')->lastname);
        $this->assertSame($this->stringField($decisions, 'bea'), $checker->decide('bea', $password)->value);
        $bea = $this->user('bea');
        $this->assertSame($this->stringField($decisions, 'bea_lastname'), $bea->lastname);
        $this->assertSame($this->stringField($decisions, 'bea_mail'), $this->mail($bea));

        $this->assertSame($this->stringField($decisions, 'lars'), $checker->decide('lars', $password)->value);
        $this->assertSame($this->stringField($decisions, 'lars_lastname'), $this->user('lars')->lastname);
        $this->assertSame($this->stringField($decisions, 'pending'), $checker->decide('pending', $password)->value);
        $this->assertSame($this->stringField($decisions, 'pending_lastname'), $this->user('pending')->lastname);
        $this->assertSame($this->stringField($decisions, 'idle'), $checker->decide('idle', $password)->value);
        $this->assertSame($this->stringField($decisions, 'idle_lastname'), $this->user('idle')->lastname);

        $this->assertSame($this->stringField($decisions, 'nova'), $checker->decide('nova', $password)->value);
        $nova = $this->user('nova');
        $this->assertSame($this->stringField($decisions, 'nova_firstname'), $nova->firstname);
        $this->assertSame($this->stringField($decisions, 'nova_lastname'), $nova->lastname);
        $this->assertSame($this->stringField($decisions, 'nova_mail'), $this->mail($nova));
        $this->assertSame((int) $loginSource->id, (int) $nova->auth_source_id);
        $this->assertFalse($passwords->verify($password, $nova->salt, $nova->hashed_password));

        $commaEntries = $client->search(
            $loginSource,
            $authenticator->filter($loginSource, $this->stringField($dn, 'login')),
            $this->stringField($dn, 'login'),
            $password,
        );
        $this->assertCount(1, $commaEntries);
        $this->assertSame($this->stringField($dn, 'comma_dn'), $commaEntries[0]->dn);
        $this->assertFalse($passwords->verify($password, $comma->salt, $comma->hashed_password));
        $this->assertSame($this->stringField($decisions, 'comma'), $checker->decide($this->stringField($dn, 'login'), $password)->value);
        $this->assertSame($this->stringField($decisions, 'comma_lastname'), $this->user($this->stringField($dn, 'login'))->lastname);

        $search = $this->section($expected, 'search');
        $found = $authenticator->searchUsers($service, $this->stringField($search, 'query'));
        $this->assertCount(1, $found);
        $this->assertSame($this->stringField($search, 'login'), $found[0]->login);
        $this->assertSame($this->stringField($search, 'firstname'), $found[0]->firstname);
        $this->assertSame([], $authenticator->searchUsers($service, ''));
        $this->assertSame([], $authenticator->searchUsers($loginSource, $this->stringField($search, 'query')));
        $blankMail = $this->source('Blank mail', [
            'account' => $this->stringField($directory, 'admin_dn'),
            'account_password' => $this->stringField($directory, 'admin_password'),
            'attr_mail' => '',
            'onthefly_register' => false,
        ]);
        $this->assertSame([], $authenticator->searchUsers($blankMail, $this->stringField($search, 'query')));

        $crowdFilter = $authenticator->userFilter($service, $this->stringField($search, 'crowd_query'));
        $unlimited = $this->logins($client->search($service, $crowdFilter));
        $crowdLogins = $search['crowd_logins'] ?? null;
        $this->assertIsArray($crowdLogins);
        $expectedCrowd = [];
        foreach ($crowdLogins as $login) {
            $this->assertIsString($login);
            $expectedCrowd[] = $login;
        }
        $this->assertSame($expectedCrowd, $unlimited);
        $limited = $this->logins($client->search($service, $crowdFilter, null, null, $this->intField($search, 'crowd_limit')));
        $this->assertCount($this->intField($search, 'crowd_limit'), $limited);
        $this->assertSame($limited, array_values(array_unique($limited)));
        $this->assertSame([], array_values(array_diff($limited, $expectedCrowd)));
        $this->assertSame(LdapAuthenticator::SEARCH_LIMIT, $this->intField($search, 'crowd_limit'));
        $matches = $authenticator->searchUsers($service, $this->stringField($search, 'crowd_query'));
        $this->assertCount($this->intField($search, 'crowd_limit'), $matches);

        $errors = $this->section($expected, 'errors');
        $blankHost = $this->source('Blank host', ['host' => '']);
        $this->assertBind($errors, 'host', function () use ($authenticator, $blankHost): void {
            $authenticator->testConnection($blankHost);
        });
        $authenticator->testConnection($service);
        $this->assertBind($errors, 'credentials', function () use ($authenticator, $bad): void {
            $authenticator->testConnection($bad);
        });
        $openOnly = $this->source('Open only', [
            'account' => $this->stringField($dn, 'live_account'),
            'account_password' => 'not-the-password',
        ]);
        $authenticator->testConnection($openOnly);
        $authenticator->testConnection($anonymous);
        $defaultPort = $this->source('Default port', ['port' => 0, 'account' => '', 'account_password' => '']);
        $authenticator->testConnection($defaultPort);

        $this->assertTimesOut($authenticator, $directory, $this->stringField($errors, 'timeout'));
        $this->assertLdaps($authenticator, $directory, $errors);
    }

    public function test_checklist_evidence_cites_this_comparison(): void
    {
        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — live LDAP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — LDAP \| VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — OpenID Connect \| N\/A \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Activity for changesets \| NOT VERIFIED \|/m', $checklist);
        $this->assertMatchesRegularExpression('/^\| Wiki and boards visual UX \| NOT VERIFIED \|/m', $checklist);
        $this->assertStringContainsString('tests/Parity/LiveLdapParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/auth/ldap-live.json', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/ldap/directory.ldif', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/', $checklist);
        $this->assertStringContainsString('MemoryLdapDirectory', $checklist);
        $this->assertStringContainsString('tests/Parity/QueryGapParityTest.php', $checklist);
        $this->assertStringContainsString('tests/Parity/fixtures/redmine-7.0.1/expectations/queries/gaps.json', $checklist);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertDirectoryPin(array $expected): void
    {
        $this->assertSame($this->intField($expected, 'port'), (int) $this->env('LDAP_PORT'));
        $this->assertSame($this->intField($expected, 'tls_port'), (int) $this->env('LDAP_TLS_PORT'));
        $this->assertSame($this->stringField($expected, 'base_dn'), $this->env('LDAP_BASE_DN'));
        $this->assertSame($this->stringField($expected, 'people_dn'), $this->env('LDAP_PEOPLE_DN'));
        $this->assertSame($this->stringField($expected, 'admin_dn'), $this->env('LDAP_ADMIN_DN'));
        $this->assertSame($this->stringField($expected, 'admin_password'), $this->env('LDAP_ADMIN_PASSWORD'));
        $this->assertSame($this->stringField($expected, 'config_dn'), $this->env('LDAP_CONFIG_DN'));
        $this->assertSame($this->stringField($expected, 'config_password'), $this->env('LDAP_CONFIG_PASSWORD'));
        $this->assertNotSame('', $this->env('LDAP_HOST'));
        $this->assertFileExists(LiveLdapSeed::ldifPath());
    }

    private function assertModes(): void
    {
        $plain = new AuthSource;
        $plain->tls = false;
        $plain->verify_peer = true;
        $this->assertSame(LdapMode::Plain, LdapMode::fromSource($plain));

        $none = new AuthSource;
        $none->tls = true;
        $none->verify_peer = false;
        $this->assertSame(LdapMode::LdapsVerifyNone, LdapMode::fromSource($none));

        $peer = new AuthSource;
        $peer->tls = true;
        $peer->verify_peer = true;
        $this->assertSame(LdapMode::LdapsVerifyPeer, LdapMode::fromSource($peer));
    }

    /**
     * @param  array<string, mixed>  $timeout
     */
    private function assertTimeouts(array $timeout): void
    {
        $blank = $this->intField($timeout, 'blank');
        $this->assertSame($blank, LdapTimeout::seconds(null));
        $this->assertSame($blank, LdapTimeout::seconds(''));
        $this->assertSame($this->intField($timeout, 'zero'), LdapTimeout::seconds(0));
        $this->assertSame($this->intField($timeout, 'negative'), LdapTimeout::seconds(-3));
        $this->assertSame($this->intField($timeout, 'stored'), LdapTimeout::seconds(4));
    }

    private function assertSchemaHasNoStartTls(): void
    {
        $schema = file_get_contents(base_path('docs/sources/redmine-7.0.1-schema.rb'));
        $this->assertIsString($schema);
        $start = strpos($schema, 'create_table "auth_sources"');
        $this->assertIsInt($start);
        $end = strpos($schema, 'create_table "', $start + 10);
        $this->assertIsInt($end);
        $slice = substr($schema, $start, $end - $start);
        $this->assertStringContainsString('t.boolean "tls"', $slice);
        $this->assertDoesNotMatchRegularExpression('/start_?tls/i', $slice);

        $client = file_get_contents(base_path('app/Domain/Auth/Ldap/ExtLdapDirectory.php'));
        $this->assertIsString($client);
        $this->assertStringNotContainsString('ldap_start_tls', $client);
    }

    /**
     * @param  array<string, mixed>  $directory
     */
    private function assertTimesOut(LdapAuthenticator $authenticator, array $directory, string $message): void
    {
        $errno = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server);
        try {
            $name = stream_socket_get_name($server, false);
            $this->assertIsString($name);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            $source = $this->source('Timeout sink', [
                'port' => $port,
                'timeout' => 1,
                'account' => $this->stringField($directory, 'admin_dn'),
                'account_password' => $this->stringField($directory, 'admin_password'),
                'tls' => false,
            ]);
            $started = microtime(true);
            try {
                $authenticator->testConnection($source);
                $this->fail('The sink accepted an LDAP bind.');
            } catch (LdapTimeoutException $exception) {
                $this->assertSame($message, $exception->getMessage());
                $this->assertLessThan(5.0, microtime(true) - $started);
            }
        } finally {
            fclose($server);
        }
    }

    /**
     * @param  array<string, mixed>  $directory
     * @param  array<string, mixed>  $errors
     */
    private function assertLdaps(LdapAuthenticator $authenticator, array $directory, array $errors): void
    {
        $peer = $this->source('LDAPS peer', [
            'port' => $this->intField($directory, 'tls_port'),
            'tls' => true,
            'verify_peer' => true,
            'account' => '',
            'account_password' => '',
        ]);
        $started = microtime(true);
        try {
            $authenticator->testConnection($peer);
            $this->fail('A self-signed certificate was accepted.');
        } catch (LdapTimeoutException $exception) {
            $this->fail($exception->getMessage());
        } catch (LdapBindException $exception) {
            $this->assertSame($this->stringField($errors, 'tls'), $exception->getMessage());
            $this->assertLessThan(3.0, microtime(true) - $started);
        }

        $none = $this->source('LDAPS none', [
            'port' => $this->intField($directory, 'tls_port'),
            'tls' => true,
            'verify_peer' => false,
            'account' => $this->stringField($directory, 'admin_dn'),
            'account_password' => $this->stringField($directory, 'admin_password'),
        ]);
        $authenticator->testConnection($none);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private function assertBind(array $errors, string $key, callable $callback): void
    {
        try {
            $callback();
            $this->fail($key);
        } catch (LdapTimeoutException $exception) {
            $this->fail($exception->getMessage());
        } catch (LdapBindException $exception) {
            $this->assertSame($this->stringField($errors, $key), $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function source(string $name, array $overrides): AuthSource
    {
        $source = AuthSource::query()->create(array_merge([
            'type' => AuthSource::TYPE_LDAP,
            'name' => $name,
            'host' => $this->env('LDAP_HOST'),
            'port' => (int) $this->env('LDAP_PORT'),
            'base_dn' => $this->env('LDAP_PEOPLE_DN'),
            'account' => '',
            'account_password' => '',
            'attr_login' => 'uid',
            'attr_firstname' => 'givenName',
            'attr_lastname' => 'sn',
            'attr_mail' => 'mail',
            'filter' => '',
            'onthefly_register' => false,
            'tls' => false,
            'verify_peer' => false,
            'timeout' => null,
        ], $overrides));
        $this->assertInstanceOf(AuthSource::class, $source);

        return $source;
    }

    private function localUser(string $login, int $status, int $sourceId, string $lastname, RedminePassword $passwords): User
    {
        $sealed = $passwords->seal(bin2hex(random_bytes(16)));
        $user = new User;
        $user->forceFill([
            'login' => $login,
            'hashed_password' => $sealed['hashed_password'],
            'salt' => $sealed['salt'],
            'firstname' => 'Local',
            'lastname' => $lastname,
            'admin' => false,
            'status' => $status,
            'type' => User::TYPE_USER,
            'language' => 'en',
            'mail_notification' => 'only_my_events',
            'must_change_passwd' => false,
            'auth_source_id' => $sourceId,
        ])->save();

        return $user;
    }

    /**
     * @param  list<LdapEntry>  $entries
     * @return list<string>
     */
    private function logins(array $entries): array
    {
        $logins = [];
        foreach ($entries as $entry) {
            $logins[] = $entry->first('uid');
        }
        sort($logins);

        return $logins;
    }

    private function mail(User $user): string
    {
        $address = EmailAddress::query()->where('user_id', $user->id)->where('is_default', true)->value('address');
        $this->assertIsString($address);

        return $address;
    }

    private function user(string $login): User
    {
        $user = User::query()->where('login', $login)->first();
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function expectation(): array
    {
        $path = Redmine701Fixture::directory().'/expectations/auth/ldap-live.json';
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame('7.0.1', $decoded['pin'] ?? null);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function section(array $expected, string $key): array
    {
        $section = $expected[$key] ?? null;
        $this->assertIsArray($section);

        return $section;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function stringField(array $section, string $key): string
    {
        $value = $section[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function intField(array $section, string $key): int
    {
        $value = $section[$key] ?? null;
        $this->assertIsInt($value);

        return $value;
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (! is_string($value) || $value === '') {
            $fromEnv = $_ENV[$name] ?? null;
            $fromServer = $_SERVER[$name] ?? null;
            $value = is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : $fromServer;
        }
        $this->assertIsString($value);
        $this->assertNotSame('', $value, $name);

        return $value;
    }
}
