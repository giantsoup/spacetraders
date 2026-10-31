<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EncryptAgentTokens extends Command
{
    protected $signature = 'spacetraders:encrypt-tokens {--backup= : Absolute existing backup directory containing database and key backups}';

    protected $description = 'Convert legacy plaintext tokens in maintenance mode, with round-trip checks and resumable progress';

    public function handle(): int
    {
        $backup = $this->option('backup');
        if (! app()->isDownForMaintenance() || ! is_string($backup) || ! is_file($backup.'/database.sqlite') || ! is_file($backup.'/app-key.txt')) {
            $this->error('Requires maintenance mode and a private backup directory containing database.sqlite and app-key.txt. Stop workers first.');

            return self::FAILURE;
        }
        try {
            if (DB::connection()->getDriverName() !== 'sqlite' || ! hash_equals(trim(file_get_contents($backup.'/app-key.txt')), config('app.key'))) {
                throw new \RuntimeException('Backup/key do not match this SQLite application.');
            }
            $restored = new \PDO('sqlite:'.$backup.'/database.sqlite');
            if ($restored->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new \RuntimeException('Backup integrity check failed.');
            }
            $statement = $restored->prepare('select token from agents where id = ?');
            foreach (DB::table('agents')->where('token_version', 0)->get() as $row) {
                $statement->execute([$row->id]);
                $saved = $statement->fetchColumn();
                if (! is_string($saved) || ! hash_equals($row->token, $saved)) {
                    throw new \RuntimeException('Backup lacks matching legacy credentials.');
                }
            }
        } catch (\Throwable) {
            $this->error('Backup restore verification failed. No credentials were converted.');

            return self::FAILURE;
        }
        foreach (DB::table('agents')->where('token_version', 0)->orderBy('id')->get() as $row) {
            DB::transaction(function () use ($row) {
                $encrypted = encrypt($row->token, false);
                if (! hash_equals($row->token, decrypt($encrypted, false))) {
                    throw new \RuntimeException('Credential conversion round-trip failed.');
                }
                DB::table('agents')->where('id', $row->id)->where('token_version', 0)->update(['token' => $encrypted, 'token_version' => 1]);
            });
            $this->line('Converted agent '.$row->id);
        }
        $this->info('Conversion complete. Preserve the backup and APP_KEY.');

        return self::SUCCESS;
    }
}
