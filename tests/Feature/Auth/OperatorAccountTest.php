<?php

namespace Tests\Feature\Auth;

use App\Console\Commands\OperatorAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperatorAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Only terminal detection is replaced; the real PTY regression covers hidden input.
        $this->app->bind(OperatorAccount::class, fn () => new class extends OperatorAccount
        {
            protected function hasTerminal(): bool
            {
                return true;
            }
        });
    }

    public function test_creation_is_one_time_and_does_not_send_mail(): void
    {
        Mail::fake();
        Notification::fake();
        $password = Str::random(24);
        $this->artisan('spacetraders:operator', ['action' => 'create'])
            ->expectsQuestion('Account email', 'operator@example.test')
            ->expectsQuestion('Account name', 'Disposable Operator')
            ->expectsConfirmation('Apply create to this account?', 'yes')
            ->expectsQuestion('New password (at least 12 characters)', $password)
            ->expectsQuestion('Confirm new password', $password)
            ->expectsOutput('Account create completed. No mail sent. Existing signed-in sessions remain available.')
            ->assertSuccessful();
        $this->assertTrue(Hash::check($password, User::sole()->password));
        $this->artisan('spacetraders:operator', ['action' => 'create'])->assertFailed();
        $this->assertDatabaseCount('users', 1);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_recovery_changes_only_the_selected_account_and_clears_its_reset_tokens(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        foreach ([$owner, $foreign] as $user) {
            DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic-old-token', 'created_at' => now()]);
        }
        $oldRemember = $owner->remember_token;
        $password = Str::random(24);
        $this->actingAs($owner)->withSession(['existing-session' => 'retained']);
        $this->artisan('spacetraders:operator', ['action' => 'recover'])
            ->expectsQuestion('Account email', $owner->email)
            ->expectsConfirmation('Apply recover to this account?', 'yes')
            ->expectsQuestion('New password (at least 12 characters)', $password)
            ->expectsQuestion('Confirm new password', $password)
            ->assertSuccessful();
        $this->assertTrue(Hash::check($password, $owner->fresh()->password));
        $this->assertNotSame($oldRemember, $owner->fresh()->remember_token);
        $this->assertSame($foreign->password, $foreign->fresh()->password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $owner->email]);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $foreign->email]);
        $this->get('/profile')->assertOk()->assertSessionHas('existing-session', 'retained');
    }

    public function test_invalid_or_unconfirmed_passwords_do_not_create_accounts(): void
    {
        foreach ([['short', 'short'], [Str::random(24), Str::random(24)], [str_repeat('x', 73), str_repeat('x', 73)]] as [$password, $confirmation]) {
            $this->artisan('spacetraders:operator', ['action' => 'create'])
                ->expectsQuestion('Account email', 'operator@example.test')
                ->expectsQuestion('Account name', 'Disposable Operator')
                ->expectsConfirmation('Apply create to this account?', 'yes')
                ->expectsQuestion('New password (at least 12 characters)', $password)
                ->expectsQuestion('Confirm new password', $confirmation)
                ->assertFailed();
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cancel_missing_account_and_noninteractive_calls_leave_no_changes(): void
    {
        $this->artisan('spacetraders:operator', ['action' => 'create', '--no-interaction' => true])->assertFailed();
        $this->artisan('spacetraders:operator', ['action' => 'invalid'])->assertFailed();
        $this->artisan('spacetraders:operator', ['action' => 'recover'])
            ->expectsQuestion('Account email', 'missing@example.test')->assertFailed();
        $this->artisan('spacetraders:operator', ['action' => 'create'])
            ->expectsQuestion('Account email', 'operator@example.test')
            ->expectsQuestion('Account name', 'Disposable Operator')
            ->expectsConfirmation('Apply create to this account?', 'no')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_recovery_failure_rolls_back_the_password_and_remember_token(): void
    {
        $user = User::factory()->create();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic-old-token', 'created_at' => now()]);
        DB::unprepared("CREATE TRIGGER refuse_reset_cleanup BEFORE DELETE ON password_reset_tokens BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END");
        $password = Str::random(24);
        $this->artisan('spacetraders:operator', ['action' => 'recover'])
            ->expectsQuestion('Account email', $user->email)
            ->expectsConfirmation('Apply recover to this account?', 'yes')
            ->expectsQuestion('New password (at least 12 characters)', $password)
            ->expectsQuestion('Confirm new password', $password)
            ->expectsOutput('Account operation failed. Verify the database and terminal, then retry. No secret details are printed.')
            ->assertFailed();
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertSame($user->remember_token, $user->fresh()->remember_token);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
