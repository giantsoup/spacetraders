<?php

namespace Tests\Feature;

use App\Models\LeaderboardSnapshot;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LeaderboardSqliteConcurrencyTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = tempnam(sys_get_temp_dir(), 'st-leaderboard-');
        config(['database.connections.sqlite.database' => $this->database]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        DB::statement('CREATE TABLE writer_probe (value TEXT)');
        $this->assertSame('wal', DB::scalar('PRAGMA journal_mode'));
        $this->assertSame(5000, DB::scalar('PRAGMA busy_timeout'));
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach ([$this->database, $this->database.'-wal', $this->database.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_another_connection_can_write_during_leaderboard_http(): void
    {
        Http::fake(function (Request $request) {
            $this->assertSame(0, DB::transactionLevel());
            $pdo = new PDO('sqlite:'.$this->database);
            $pdo->exec('PRAGMA busy_timeout=0');
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec("INSERT INTO writer_probe VALUES ('during HTTP')");
            $pdo->exec('COMMIT');

            return Http::response($this->statusBody());
        });

        $this->artisan('leaderboard:capture --if-due --top-only')->assertExitCode(0);

        $this->assertSame('during HTTP', DB::table('writer_probe')->sole()->value);
        $this->assertSame('2026-11-20', LeaderboardSnapshot::sole()->reset_date);
    }

    public function test_snapshot_insert_waits_for_a_concurrent_sqlite_writer(): void
    {
        $writer = new Process([PHP_BINARY, '-r', <<<'PHP'
            $pdo = new PDO('sqlite:'.$argv[1]);
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec("INSERT INTO writer_probe VALUES ('held writer')");
            echo "READY\n";
            fflush(STDOUT);
            usleep(350000);
            $pdo->exec('COMMIT');
            PHP, $this->database], timeout: 5);
        Http::fake(function (Request $request) use ($writer) {
            $this->assertSame(0, DB::transactionLevel());
            if (str_ends_with($request->url(), '/v2/')) {
                return Http::response($this->statusBody());
            }
            $writer->start();
            $this->assertTrue($writer->waitUntil(fn ($type, $output) => str_contains($output, "READY\n")));
            $this->assertTrue($writer->isRunning());

            return Http::response(['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'limit' => 20]]);
        });

        try {
            $this->artisan('leaderboard:capture --if-due')->assertExitCode(0);
            $this->assertSame(0, $writer->wait());
            $this->assertSame('held writer', DB::table('writer_probe')->sole()->value);
            $this->assertSame(0, LeaderboardSnapshot::sole()->agents_total);
        } finally {
            $writer->stop();
        }
    }

    private function statusBody(): array
    {
        return ['resetDate' => '2026-11-20', 'leaderboards' => ['mostCredits' => [], 'mostSubmittedCharts' => []]];
    }
}
