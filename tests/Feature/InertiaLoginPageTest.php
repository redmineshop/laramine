<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaLoginPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_is_the_inertia_screen(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('submitUrl', '/login')
                ->where('lostPasswordUrl', '/account/lost_password')
                ->where('registerUrl', '/account/register')
                ->where('activationEmailUrl', null)
                ->where('notice', null)
                ->where('autologinDays', 0)
                ->has('errors')
            );
    }

    public function test_inertia_sign_in_starts_a_session_and_redirects(): void
    {
        $user = $this->account();
        $digest = $user->hashed_password;

        $this->post('/login', [
            'login' => 'ada',
            'password' => 'secret',
        ], $this->inertiaHeaders())->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->last_login_on);
        $this->assertSame($digest, $fresh->hashed_password);
    }

    public function test_inertia_wrong_password_keeps_the_guest_and_shares_the_error(): void
    {
        $this->account();

        $this->from('/login')->post('/login', [
            'login' => 'ada',
            'password' => 'wrong',
        ], $this->inertiaHeaders())
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'login' => 'Invalid user or password',
            ]);

        $this->assertGuest();

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('errors.login', 'Invalid user or password')
            );
    }

    /**
     * Headers the Inertia client sends on a form post.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    private function account(): User
    {
        return User::factory()->withPassword('secret')->create([
            'login' => 'ada',
        ]);
    }
}
