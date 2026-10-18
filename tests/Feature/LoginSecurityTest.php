<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Notifications\EmailCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_signs_in_to_the_admin_dashboard(): void
    {
        $user = User::factory()->admin()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'Signed in as administrator.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_client_signs_in_to_the_portal(): void
    {
        $user = User::factory()->create(['name' => 'Ana Reyes']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertRedirect(route('portal.dashboard'))
            ->assertSessionHas('status', 'Welcome back, Ana Reyes.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_welcome_message_escapes_the_name(): void
    {
        $user = User::factory()->create(['name' => '<script>alert(1)</script>']);
        Client::openAccount(['user_id' => $user->id, 'name' => 'Client']);

        $this->followingRedirects()->post('/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_page_opened_before_sign_in_is_kept_when_it_belongs_to_the_role(): void
    {
        $user = User::factory()->admin()->create();

        $this->get(route('admin.plans.index'))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])->assertRedirect(route('admin.plans.index'));
    }

    public function test_client_with_an_admin_link_open_is_sent_to_the_portal(): void
    {
        $user = User::factory()->create();

        $this->get(route('admin.plans.index'))->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])->assertRedirect(route('portal.dashboard'));
    }

    public function test_missing_password_is_reported_on_the_password_field(): void
    {
        $this->post('/login', ['email' => 'someone@example.com', 'password' => ''])
            ->assertSessionHasErrors(['password' => 'The password field is required.']);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'password1'])
            ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);
    }

    public function test_there_is_no_public_sign_up(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'x@example.com', 'password' => 'Secret#123'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_signed_in_users_cannot_open_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('home'));
    }

    public function test_pages_tell_the_browser_not_to_keep_a_copy(): void
    {
        $this->get('/login')->assertHeader('Pragma', 'no-cache');
        $this->assertStringContainsString('no-store', $this->get('/login')->headers->get('Cache-Control'));

        $admin = User::factory()->admin()->create();
        $this->assertStringContainsString('no-store', $this->actingAs($admin)->get(route('admin.dashboard'))->headers->get('Cache-Control'));
    }

    public function test_after_sign_out_the_dashboard_sends_back_to_login(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post('/logout')->assertRedirect(route('login'));

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_wrong_password_does_not_reveal_whether_the_account_exists(): void
    {
        $user = User::factory()->create();

        $known = $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $unknown = $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'nope'])->assertSessionHasErrors('email');

        $this->assertSame(session('errors')->first('email'), 'These credentials do not match our records.');
        $this->assertGuest();
    }

    public function test_five_wrong_passwords_lock_the_account_on_that_ip(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'.$i]);
        }

        // Even the right password is refused while locked.
        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])
            ->assertSessionHasErrors(['email' => 'Too many failed sign-in attempts. Please try again in 15 minutes or reset your password.']);
        $this->assertGuest();
    }

    public function test_ip_is_throttled_across_accounts(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "user{$i}@example.com", 'password' => 'x']);
        }

        $this->post('/login', ['email' => 'another@example.com', 'password' => 'x'])->assertStatus(429);
    }

    public function test_disabled_account_cannot_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password1'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unverified_user_is_sent_to_the_code_page_and_gets_a_code(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/home')->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()->assertSee('Verify your email');

        Notification::assertSentTo($user, EmailCodeNotification::class);
    }

    public function test_pages_send_security_headers(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }
}
