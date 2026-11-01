<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivateAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_account_routes_are_absent_for_guests_and_existing_users(): void
    {
        Mail::fake();
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        $foreign = User::factory()->create();
        foreach ([null, $owner, $foreign] as $user) {
            if ($user) {
                $this->actingAs($user);
            }
            foreach ([['GET', '/register'], ['POST', '/register'], ['GET', '/forgot-password'], ['POST', '/forgot-password'], ['GET', '/reset-password/old-token'], ['POST', '/reset-password'], ['GET', '/verify-email'], ['GET', '/verify-email/'.$owner->id.'/'.sha1($owner->email)], ['POST', '/email/verification-notification']] as [$method, $uri]) {
                $this->call($method, $uri, ['email' => $owner->email, 'password' => 'disposable-test-password'])->assertNotFound();
            }
        }
        foreach (['register', 'password.request', 'password.email', 'password.reset', 'password.store', 'verification.notice', 'verification.verify', 'verification.send'] as $name) {
            $this->assertFalse(Route::has($name));
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_login_remains_throttled(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/agents');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_change_targets_only_the_signed_in_user_and_preserves_the_session(): void
    {
        $owner = User::factory()->unverified()->create();
        $foreign = User::factory()->create();
        $foreignHash = $foreign->password;
        $this->actingAs($owner)->withSession(['existing-session' => 'retained'])
            ->put('/password', ['user_id' => $foreign->id, 'email' => $foreign->email, 'current_password' => 'password', 'password' => 'changed-fixture-password', 'password_confirmation' => 'changed-fixture-password'])
            ->assertSessionHasNoErrors()->assertSessionHas('existing-session', 'retained');
        $this->assertTrue(Hash::check('changed-fixture-password', $owner->fresh()->password));
        $this->assertSame($foreignHash, $foreign->fresh()->password);
        $this->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Profile/Edit')->missing('mustVerifyEmail'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_mail_transport_cannot_be_switched_to_delivery_or_logging_by_environment(): void
    {
        $previous = $_ENV['MAIL_MAILER'] ?? null;
        $_ENV['MAIL_MAILER'] = 'log';
        try {
            $mail = require base_path('config/mail.php');
            $this->assertSame('array', $mail['default']);
            $this->assertSame(['array' => ['transport' => 'array']], $mail['mailers']);
            config(['mail' => $mail]);
            Mail::raw('Disposable non-delivery probe', fn ($message) => $message->to('fixture@example.test')->subject('Offline probe'));
            $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
        } finally {
            if ($previous === null) {
                unset($_ENV['MAIL_MAILER']);
            } else {
                $_ENV['MAIL_MAILER'] = $previous;
            }
        }
    }

    public function test_guests_cannot_change_passwords_or_access_owned_gameplay(): void
    {
        $this->put('/password', [])->assertRedirect('/login');
        $this->get('/agents')->assertRedirect('/login');
        $this->post('/agents/1/commands/preview', [])->assertRedirect('/login');
    }
}
