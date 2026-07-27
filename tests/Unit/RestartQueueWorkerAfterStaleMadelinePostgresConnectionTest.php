<?php

namespace Tests\Unit;

use App\Jobs\DownloadMediaAssetJob;
use App\Listeners\RestartQueueWorkerAfterStaleMadelinePostgresConnection;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RestartQueueWorkerAfterStaleMadelinePostgresConnectionTest extends TestCase
{
    public function test_it_restarts_the_worker_after_a_stale_amp_prepared_statement(): void
    {
        Log::spy();
        $worker = app('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);
        $worker->shouldQuit = false;
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->once()->andReturn('telegram');
        $job->shouldReceive('resolveName')->once()->andReturn(DownloadMediaAssetJob::class);
        $exception = new RuntimeException(
            'MadelineProto query failed.',
            previous: new RuntimeException(
                'ERROR: prepared statement "amp_d8de59b9dead592de9c7299512e1f83dea7d56f7" does not exist',
            ),
        );

        (new RestartQueueWorkerAfterStaleMadelinePostgresConnection)->handle(
            new JobExceptionOccurred('redis', $job, $exception),
        );

        $this->assertTrue($worker->shouldQuit);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_it_keeps_the_worker_running_after_an_unrelated_exception(): void
    {
        Log::spy();
        $worker = app('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);
        $worker->shouldQuit = false;
        $job = Mockery::mock(Job::class);

        (new RestartQueueWorkerAfterStaleMadelinePostgresConnection)->handle(
            new JobExceptionOccurred(
                'redis',
                $job,
                new RuntimeException('Telegram media is unavailable.'),
            ),
        );

        $this->assertFalse($worker->shouldQuit);
        Log::shouldNotHaveReceived('warning');
    }
}
