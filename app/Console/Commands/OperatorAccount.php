<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class OperatorAccount extends Command
{
    protected $signature = 'spacetraders:operator {action : create or recover}';

    protected $description = 'Create the first private account or recover an existing account through hidden terminal prompts';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['create', 'recover'], true) || ! $this->input->isInteractive() || ! $this->hasTerminal()) {
            $this->error('Use create or recover in an interactive terminal. No password arguments are accepted.');

            return self::FAILURE;
        }

        try {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $this->error('Operator provisioning requires the supported SQLite database.');

                return self::FAILURE;
            }
            if ($action === 'create' && User::exists()) {
                $this->error('An account already exists. Use recover for an existing account.');

                return self::FAILURE;
            }
            $email = $this->ask('Account email');
            $name = $action === 'create' ? $this->ask('Account name') : null;
            if (Validator::make(['email' => $email, 'name' => $name], [
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => [$action === 'create' ? 'required' : 'nullable', 'string', 'max:255'],
            ])->fails()) {
                $this->error('Enter a valid email and, for creation, a name of at most 255 characters.');

                return self::FAILURE;
            }
            if ($action === 'recover' && ! User::where('email', $email)->exists()) {
                $this->error('No matching account. No changes made.');

                return self::FAILURE;
            }
            if (! $this->confirm('Apply '.$action.' to this account?', false)) {
                return self::FAILURE;
            }

            // Never fall back to visible input. Do not print validation or exception payloads.
            $password = $this->secret('New password (at least 12 characters)', false);
            $confirmation = $this->secret('Confirm new password', false);
            if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || $password !== $confirmation) {
                $this->error('Passwords must match and contain at least 12 characters and at most 72 bytes. No changes made.');

                return self::FAILURE;
            }
            $hash = Hash::make($password);
            unset($password, $confirmation);

            DB::transaction(function () use ($action, $email, $name, $hash) {
                if ($action === 'create') {
                    if (User::exists()) {
                        throw new \RuntimeException('Account exists.');
                    }
                    User::create(['email' => $email, 'name' => $name, 'password' => $hash]);
                } else {
                    $user = User::where('email', $email)->lockForUpdate()->sole();
                    $user->forceFill(['password' => $hash, 'remember_token' => Str::random(60)])->save();
                    DB::table('password_reset_tokens')->where('email', $email)->delete();
                }
            });
        } catch (Throwable) {
            $this->error('Account operation failed. Verify the database and terminal, then retry. No secret details are printed.');

            return self::FAILURE;
        }

        $this->info('Account '.$action.' completed. No mail sent. Existing signed-in sessions remain available.');

        return self::SUCCESS;
    }

    protected function hasTerminal(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }
}
