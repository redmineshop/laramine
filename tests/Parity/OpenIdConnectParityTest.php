<?php

namespace Tests\Parity;

use App\Domain\Auth\Oauth\Pkce;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Parity\Support\Redmine701Fixture;
use Tests\TestCase;

/**
 * Redmine 7.0.1 core ships OAuth provider tables, not OpenID Connect.
 */
class OpenIdConnectParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_pin_has_oauth_tables_and_no_openid_connect(): void
    {
        $path = base_path(Redmine701Fixture::SCHEMA_PIN);
        $raw = file_get_contents($path);
        $this->assertIsString($raw);
        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $this->assertIsArray($lines);

        $this->assertSame('  create_table "oauth_access_grants", force: :cascade do |t|', $lines[389]);
        $this->assertSame('    t.string "code_challenge"', $lines[391]);
        $this->assertSame('    t.string "code_challenge_method"', $lines[392]);
        $this->assertSame('  create_table "oauth_access_tokens", force: :cascade do |t|', $lines[404]);
        $this->assertSame('  create_table "oauth_applications", force: :cascade do |t|', $lines[420]);
        $this->assertSame('  create_table "settings", force: :cascade do |t|', $lines[537]);

        $tables = [];
        foreach ($lines as $number => $line) {
            if ($number < 23 || $number > 708) {
                continue;
            }
            if (preg_match('/create_table "([^"]+)"/', $line, $match) === 1) {
                $tables[] = $match[1];
            }
        }
        $this->assertContains('oauth_access_grants', $tables);
        $this->assertContains('oauth_access_tokens', $tables);
        $this->assertContains('oauth_applications', $tables);
        $this->assertNotContains('openid_connect', $tables);
        $this->assertDoesNotMatchRegularExpression('/id_token|openid|omniauth/i', $raw);

        $checklist = file_get_contents(base_path('docs/parity-checklist.md'));
        $this->assertIsString($checklist);
        $this->assertMatchesRegularExpression('/^\| Users and authentication — OpenID Connect \| N\/A \|/m', $checklist);
        $this->assertStringContainsString('docs/sources/redmine-7.0.1-schema.rb', $checklist);
        $this->assertStringContainsString('lines 390–431', $checklist);
        $this->assertStringContainsString('tests/Parity/OpenIdConnectParityTest.php', $checklist);
    }

    public function test_token_response_has_no_id_token_and_discovery_is_absent(): void
    {
        Redmine701Fixture::load();
        $admin = User::query()->where('login', 'admin')->firstOrFail();
        $ada = User::query()->where('login', 'ada')->firstOrFail();
        $verifier = str_repeat('a', 43);
        $challenge = Pkce::s256($verifier);
        $redirect = 'https://client.example/callback';

        $created = $this->actingAs($admin)->postJson('/oauth/applications', [
            'name' => 'Pin client',
            'redirect_uri' => $redirect,
            'scopes' => 'read',
            'confidential' => '1',
        ])->assertCreated();
        $uid = $created->json('uid');
        $secret = $created->json('secret');
        $this->assertIsString($uid);
        $this->assertIsString($secret);

        $approved = $this->actingAs($ada)->post('/oauth/authorize', [
            'client_id' => $uid,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'approve' => '1',
        ]);
        $location = $approved->headers->get('Location');
        $this->assertIsString($location);
        $query = parse_url($location, PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $params);
        $code = $params['code'] ?? null;
        $this->assertIsString($code);
        $this->assertNotNull(DB::table('oauth_access_grants')->where('token', $code)->first());

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $uid,
            'client_secret' => $secret,
            'code' => $code,
            'redirect_uri' => $redirect,
            'code_verifier' => $verifier,
        ])->assertOk();
        $this->assertArrayNotHasKey('id_token', $token->json());
        $this->get('/.well-known/openid-configuration')->assertNotFound();
    }
}
