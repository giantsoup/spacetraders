<?php

namespace Tests\Unit\Engine;

use App\Gameplay\Engine\EngineChildWatch;
use App\Gameplay\Engine\EngineLease;
use App\Models\LocalEngine;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EngineChildWatchTest extends TestCase
{
    use RefreshDatabase;

    private string $session = '11111111-2222-3333-4444-555555555555';

    private $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SPACETRADERS_ENGINE_SESSION='.$this->session);
        putenv('SPACETRADERS_ENGINE_ROLE=worker');
        $path = EngineLease::path($this->session, 'supervisor');
        mkdir(dirname($path), 0700, true);
        $this->supervisor = fopen($path, 'c+b');
        flock($this->supervisor, LOCK_EX | LOCK_NB);
        LocalEngine::create(['id' => 1, 'session_id' => $this->session, 'state' => 'running', 'gate_open' => true]);
    }

    protected function tearDown(): void
    {
        putenv('SPACETRADERS_ENGINE_SESSION');
        putenv('SPACETRADERS_ENGINE_ROLE');
        if (is_resource($this->supervisor)) {
            fclose($this->supervisor);
        }
        parent::tearDown();
    }

    #[TestWith(['missing'])]
    #[TestWith(['session'])]
    #[TestWith(['stop'])]
    #[TestWith(['orphan'])]
    public function test_stop_conditions(string $condition): void
    {
        match ($condition) {
            'missing' => LocalEngine::find(1)->delete(),
            'session' => LocalEngine::find(1)->update(['session_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']),
            'stop' => LocalEngine::find(1)->update(['stop_requested_at' => now()]),
            'orphan' => flock($this->supervisor, LOCK_UN),
        };
        $this->assertTrue((new EngineChildWatch)->shouldStop());
    }

    public function test_database_reads_are_throttled_but_orphan_detection_is_immediate(): void
    {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'local_engines')) {
                $queries++;
            }
        });
        $watch = new EngineChildWatch;
        for ($iteration = 0; $iteration < 10; $iteration++) {
            $this->assertFalse($watch->shouldStop());
        }
        $this->assertSame(1, $queries);
        usleep(1050000);
        $this->assertFalse($watch->shouldStop());
        $this->assertSame(2, $queries);
        flock($this->supervisor, LOCK_UN);
        $this->assertTrue($watch->shouldStop());
        $this->assertSame(2, $queries);
    }

    public function test_database_stop_is_observed_after_the_throttle_interval(): void
    {
        $watch = new EngineChildWatch;
        $this->assertFalse($watch->shouldStop());
        LocalEngine::whereKey(1)->update(['stop_requested_at' => now()]);
        $this->assertFalse($watch->shouldStop());
        usleep(1050000);
        $this->assertTrue($watch->shouldStop());
    }

    public function test_looping_prevents_another_pop_and_requests_worker_exit(): void
    {
        EngineChildWatch::listen();
        LocalEngine::whereKey(1)->delete();
        $this->assertFalse(Event::until(new Looping('database', 'spacetraders')));
        $this->assertTrue(app('queue.worker')->shouldQuit);
    }

    #[TestWith([JobProcessed::class])]
    #[TestWith([JobFailed::class])]
    #[TestWith([JobExceptionOccurred::class])]
    public function test_job_markers_publish_an_idle_content_record_on_each_terminal_event(string $event): void
    {
        EngineChildWatch::listen();
        $this->assertNull(EngineChildWatch::jobStartedAt($this->session));
        $before = microtime(true);
        $job = $this->createStub(Job::class);
        $job->method('payload')->willReturn([]);
        Event::dispatch(new JobProcessing('database', $job));
        $started = EngineChildWatch::jobStartedAt($this->session);
        $this->assertNotNull($started);
        $this->assertGreaterThanOrEqual($before, $started);
        $this->assertLessThanOrEqual(microtime(true), $started);
        $this->assertSame([], glob(config('gameplay.lock_path').'/engine/*.tmp'));
        Event::dispatch(new $event('database', $job, new \RuntimeException('test')));
        $this->assertNull(EngineChildWatch::jobStartedAt($this->session));
        $marker = json_decode(file_get_contents($this->markerPath()), true);
        $this->assertNull($marker['started_at']);
        $this->assertIsFloat($marker['finished_at']);
        $this->assertSame([], glob($this->markerPath().'.*.lease'));
    }

    private function markerPath(): string
    {
        return config('gameplay.lock_path').'/engine/'.$this->session.'-worker.job';
    }

    public function test_failed_start_publication_logs_without_failing_the_job(): void
    {
        EngineChildWatch::listen();
        mkdir($this->markerPath());
        Log::partialMock();
        Log::shouldReceive('channel')->with('engine')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Cannot publish engine job watchdog marker.', \Mockery::type('array'));
        Event::dispatch(new JobProcessing('database', $this->createStub(Job::class)));
        $this->assertNull(EngineChildWatch::jobStartedAt($this->session));
        $this->assertSame([], glob($this->markerPath().'.*.lease'));
        $this->assertSame([], glob($this->markerPath().'.*.tmp'));
    }

    #[TestWith([JobProcessed::class])]
    #[TestWith([JobFailed::class])]
    #[TestWith([JobExceptionOccurred::class])]
    public function test_a_stale_start_marker_cannot_time_out_a_finished_attempt_even_if_finish_publication_fails(string $event): void
    {
        EngineChildWatch::listen();
        $job = $this->createStub(Job::class);
        $job->method('payload')->willReturn([]);
        Event::dispatch(new JobProcessing('database', $job));
        $stale = file_get_contents($this->markerPath());
        unlink($this->markerPath());
        mkdir($this->markerPath());
        Log::partialMock();
        Log::shouldReceive('channel')->with('engine')->andReturnSelf();
        Log::shouldReceive('warning')->once()->with('Cannot finish engine job watchdog marker.', \Mockery::type('array'));
        Event::dispatch(new $event('database', $job, new \RuntimeException('test')));
        rmdir($this->markerPath());
        file_put_contents($this->markerPath(), $stale);
        $this->assertNull(EngineChildWatch::jobStartedAt($this->session));
        $this->assertSame([], glob($this->markerPath().'.*.lease'));
    }

    public function test_marker_publication_retries_a_transient_replace_failure(): void
    {
        EngineChildWatch::listen();
        mkdir($this->markerPath());
        $process = proc_open([PHP_BINARY, '-r', 'echo "ready\n"; fflush(STDOUT); fgets(STDIN); usleep(50000); rmdir($argv[1]);', $this->markerPath()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 10);
            $this->assertSame("ready\n", fgets($pipes[1]));
            fwrite($pipes[0], "go\n");
            $job = $this->createStub(Job::class);
            Event::dispatch(new JobProcessing('database', $job));
            $this->assertNotNull(EngineChildWatch::jobStartedAt($this->session));
            Event::dispatch(new JobProcessed('database', $job));
        } finally {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    public function test_marker_listeners_do_not_throw_even_when_warning_logging_fails(): void
    {
        EngineChildWatch::listen();
        mkdir($this->markerPath());
        Log::partialMock();
        Log::shouldReceive('channel')->with('engine')->andThrow(new \RuntimeException('logging unavailable'));
        $job = $this->createStub(Job::class);
        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));
        $this->assertNull(EngineChildWatch::jobStartedAt($this->session));
    }

    public function test_normal_cli_has_no_watch_or_queue_listeners(): void
    {
        putenv('SPACETRADERS_ENGINE_SESSION');
        putenv('SPACETRADERS_ENGINE_ROLE');
        $listeners = Event::getListeners(JobProcessing::class);
        EngineChildWatch::listen();
        $this->assertFalse((new EngineChildWatch)->shouldStop());
        $this->assertCount(count($listeners), Event::getListeners(JobProcessing::class));
    }
}
