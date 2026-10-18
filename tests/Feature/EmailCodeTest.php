<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailCodeNotification;
use App\Support\EmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailCodeTest extends TestCase
{
    use RefreshDatabase;

    private function sentCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, EmailCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_correct_code_verifies_the_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'));
        $code = $this->sentCode($user);

        // Only a hash is stored.
        $row = DB::table('email_codes')->first();
        $this->assertNotSame($code, $row->code_hash);
        $this->assertTrue(Hash::check($code, $row->code_hash));

        $this->post(route('verification.verify'), ['code' => $code])->assertRedirect(route('home'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        // Single use.
        $this->assertSame('none', EmailCode::check($user->email, EmailCode::VERIFY, $code));
    }

    public function test_five_wrong_codes_cancel_it(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        EmailCode::send($user, $user->email, EmailCode::VERIFY);
        $code = $this->sentCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $i) {
            $this->assertSame('wrong', EmailCode::check($user->email, EmailCode::VERIFY, $wrong));
        }
        $this->assertSame('locked', EmailCode::check($user->email, EmailCode::VERIFY, $wrong));
        $this->assertSame('none', EmailCode::check($user->email, EmailCode::VERIFY, $code));
    }

    public function test_expired_code_is_refused(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        EmailCode::send($user, $user->email, EmailCode::VERIFY);
        $code = $this->sentCode($user);

        $this->travel(EmailCode::MINUTES + 1)->minutes();

        $this->assertSame('expired', EmailCode::check($user->email, EmailCode::VERIFY, $code));
    }

    public function test_resend_has_a_cooldown_and_an_hourly_cap(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->assertSame('sent', EmailCode::send($user, $user->email, EmailCode::VERIFY));
        $this->assertSame('cooldown', EmailCode::send($user, $user->email, EmailCode::VERIFY));

        foreach (range(2, 5) as $i) {
            $this->travel(61)->seconds();
            $this->assertSame('sent', EmailCode::send($user, $user->email, EmailCode::VERIFY));
        }
        $this->travel(61)->seconds();
        $this->assertSame('limit', EmailCode::send($user, $user->email, EmailCode::VERIFY));
    }

    public function test_password_reset_with_code(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();
        $code = $this->sentCode($user);

        $this->post(route('password.update'), [
            'email' => $user->email, 'code' => $code,
            'password' => 'NewPass#123', 'password_confirmation' => 'NewPass#123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NewPass#123', $user->fresh()->password));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    #[DataProvider('weakPasswords')]
    public function test_password_reset_rejects_weak_passwords(string $password, string $message): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);
        $code = $this->sentCode($user);

        $this->post(route('password.update'), [
            'email' => $user->email, 'code' => $code,
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password' => $message]);

        $this->assertTrue(Hash::check('password1', $user->fresh()->password));
    }

    /** @return array<string, array{string, string}> */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Ab#1', 'The password field must be at least 8 characters.'],
            'no uppercase' => ['newpass#123', 'The password field must contain at least one uppercase and one lowercase letter.'],
            'no lowercase' => ['NEWPASS#123', 'The password field must contain at least one uppercase and one lowercase letter.'],
            'no number' => ['NewPass#abc', 'The password field must contain at least one number.'],
            'no symbol' => ['NewPass123', 'The password field must contain at least one symbol.'],
        ];
    }

    public function test_password_reset_rejects_the_current_password(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'Current#123']);

        $this->post(route('password.email'), ['email' => $user->email]);
        $code = $this->sentCode($user);

        $this->post(route('password.update'), [
            'email' => $user->email, 'code' => $code,
            'password' => 'Current#123', 'password_confirmation' => 'Current#123',
        ])->assertSessionHasErrors(['password' => 'Your new password must be different from your current password.']);
    }

    public function test_forgot_password_answers_the_same_for_unknown_emails(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'ghost@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'If an account uses that email'));

        Notification::assertNothingSent();
    }
}
