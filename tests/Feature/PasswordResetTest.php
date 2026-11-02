<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    public function test_reset_mail_is_sent_to_an_active_user(): void
    {
        Notification::fake();
        $r = $this->makeRetailer();

        $this->postJson('/api/forgot-password', ['email' => $r->user->email])->assertOk();

        Notification::assertSentTo($r->user, ResetPassword::class);
    }

    public function test_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@test.local'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_deactivated_user_gets_no_mail(): void
    {
        Notification::fake();
        $r = $this->makeRetailer(0, 'inactive');

        $this->postJson('/api/forgot-password', ['email' => $r->user->email])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_valid_token_changes_password_and_signs_out_all_devices(): void
    {
        $r = $this->makeRetailer();
        $r->user->createToken('old-device');
        $token = Password::broker()->createToken($r->user);

        $this->postJson('/api/reset-password', [
            'token' => $token, 'email' => $r->user->email,
            'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertOk();

        $this->assertSame(0, $r->user->tokens()->count());
        $this->postJson('/api/login', ['login' => $r->user->email, 'password' => 'NewSecret456', 'role' => 'retailer'])->assertOk();
    }

    public function test_token_cannot_be_used_twice(): void
    {
        $r     = $this->makeRetailer();
        $token = Password::broker()->createToken($r->user);
        $body  = ['token' => $token, 'email' => $r->user->email, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456'];

        $this->postJson('/api/reset-password', $body)->assertOk();
        $this->postJson('/api/reset-password', $body)->assertStatus(422);
    }

    public function test_wrong_token_and_weak_password_are_rejected(): void
    {
        $r = $this->makeRetailer();

        $this->postJson('/api/reset-password', [
            'token' => 'wrong', 'email' => $r->user->email, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertStatus(422);

        $this->postJson('/api/reset-password', [
            'token' => Password::broker()->createToken($r->user), 'email' => $r->user->email, 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);
    }
}
