<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MailDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS lookups: "nowhere.invalid" is the only provider that doesn't exist.
        $this->mock(MailDomains::class, function (MockInterface $mock) {
            $mock->shouldReceive('receivesMail')->andReturnUsing(fn (string $domain) => $domain !== 'nowhere.invalid');
        });
    }

    public function test_incomplete_address_is_reported(): void
    {
        $this->postJson(route('email.check'), ['email' => 'juan@gmail', 'context' => 'existing'])
            ->assertOk()
            ->assertExactJson(['ok' => false, 'message' => 'Enter a complete email address, like name@gmail.com.']);
    }

    public function test_unknown_email_provider_is_reported(): void
    {
        $this->postJson(route('email.check'), ['email' => 'juan@nowhere.invalid', 'context' => 'existing'])
            ->assertExactJson(['ok' => false, 'message' => 'We can\'t find the email provider "nowhere.invalid". Please check for typos.']);
    }

    public function test_login_check_answers_the_same_for_registered_and_unknown_emails(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $registered = $this->postJson(route('email.check'), ['email' => $user->email, 'context' => 'existing'])->json();
        $unknown = $this->postJson(route('email.check'), ['email' => 'nobody@example.com', 'context' => 'existing'])->json();

        $this->assertSame(['ok' => true, 'message' => ''], $registered);
        $this->assertSame($registered, $unknown);
    }

    public function test_admin_sees_that_an_email_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('email.check'), ['email' => ' Ana@Example.com ', 'context' => 'new'])
            ->assertExactJson(['ok' => false, 'message' => 'This email already has an account.']);
    }

    public function test_admin_sees_that_an_email_is_available(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('email.check'), ['email' => 'new@example.com', 'context' => 'new'])
            ->assertExactJson(['ok' => true, 'message' => 'This email is available.']);
    }

    public function test_guests_cannot_ask_whether_an_email_has_an_account(): void
    {
        $this->postJson(route('email.check'), ['email' => 'ana@example.com', 'context' => 'new'])->assertForbidden();
    }

    public function test_customers_cannot_ask_whether_an_email_has_an_account(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('email.check'), ['email' => 'ana@example.com', 'context' => 'new'])
            ->assertForbidden();
    }

    public function test_context_must_be_known(): void
    {
        $this->postJson(route('email.check'), ['email' => 'ana@example.com', 'context' => 'register'])
            ->assertJsonValidationErrors(['context' => 'The selected context is invalid.']);
    }
}
