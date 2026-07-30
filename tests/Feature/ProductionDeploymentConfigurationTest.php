<?php

namespace Tests\Feature;

use App\Jobs\DownloadMediaAssetJob;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_deploy_pauses_and_restores_all_long_running_queues(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");

        foreach ([
            'PUBLISH_QUEUE',
            'TELEGRAM_QUEUE',
            'MEDIA_DOWNLOAD_HIGH_QUEUE',
            'MEDIA_DOWNLOAD_LOW_QUEUE',
        ] as $queueVariable) {
            $this->assertStringContainsString(
                "queue:pause \$({$queueVariable})",
                $deploy,
            );
            $this->assertSame(
                1,
                substr_count($deploy, "queue:continue \$({$queueVariable})"),
            );
        }
    }

    public function test_media_downloads_use_one_strictly_ordered_supervisor_with_safe_timeouts(): void
    {
        $telegramSupervisor = config('horizon.environments.production.supervisor-telegram');
        $mediaSupervisor = config('horizon.environments.production.supervisor-media-download');
        $job = new DownloadMediaAssetJob(1);
        $retryAfter = config('queue.connections.redis.retry_after');
        $operationTimeout = config('services.telegram.media_operation_timeout_seconds');

        $this->assertSame(['telegram'], $telegramSupervisor['queue']);
        $this->assertSame(3, $telegramSupervisor['maxProcesses']);
        $this->assertSame([
            DownloadMediaAssetJob::HIGH_PRIORITY_QUEUE,
            DownloadMediaAssetJob::BACKGROUND_QUEUE,
        ], $mediaSupervisor['queue']);
        $this->assertFalse($mediaSupervisor['balance']);
        $this->assertSame(1, $mediaSupervisor['maxProcesses']);
        $this->assertSame(1, $mediaSupervisor['tries']);
        $this->assertSame(360, $mediaSupervisor['timeout']);
        $this->assertIsInt($retryAfter);
        $this->assertIsFloat($operationTimeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame(7200, $job->uniqueFor);
        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertContainsOnlyInstancesOf(FailOnException::class, $job->middleware());
        $this->assertTrue($operationTimeout < $job->timeout);
        $this->assertTrue($job->timeout < $mediaSupervisor['timeout']);
        $this->assertTrue($mediaSupervisor['timeout'] < $retryAfter);
    }

    public function test_media_download_job_fails_on_the_first_exception(): void
    {
        $job = (new DownloadMediaAssetJob(1))->withFakeQueueInteractions();
        $exception = new RuntimeException('Telegram IPC failed.');

        try {
            $job->middleware()[0]->handle(
                $job,
                static fn (): never => throw $exception,
            );
            $this->fail('The media download middleware did not propagate the exception.');
        } catch (RuntimeException $thrownException) {
            $this->assertSame($exception, $thrownException);
        }

        $job->assertFailedWith($exception);
        $job->assertNotReleased();
    }

    public function test_deploy_stops_workers_before_updating_code_and_resumes_after_readiness(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");
        $restartHorizon = Str::between(
            $makefile,
            "restart-horizon:\n",
            "\n# Restart Scheduler",
        );

        $pauseHorizonPosition = strpos($deploy, 'exec horizon php artisan horizon:pause');
        $waitForMediaPosition = strpos($deploy, 'telegram:wait-for-media-downloads');
        $stopHorizonPosition = strpos($deploy, 'stop --timeout 370 horizon');
        $stopMadelinePosition = strpos($deploy, 'stop --timeout 120 madeline');
        $composerInstallPosition = strpos($deploy, 'composer install');
        $migratePosition = strpos($deploy, 'artisan migrate --force');
        $reconcilePosition = strpos($deploy, '$(MAKE) reconcile-containers');
        $healthPosition = strpos($deploy, 'exec madeline php artisan telegram:health');
        $continueTelegramPosition = strrpos($deploy, 'queue:continue $(TELEGRAM_QUEUE)');

        $this->assertIsInt($pauseHorizonPosition);
        $this->assertIsInt($waitForMediaPosition);
        $this->assertIsInt($stopHorizonPosition);
        $this->assertIsInt($stopMadelinePosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($migratePosition);
        $this->assertIsInt($reconcilePosition);
        $this->assertIsInt($healthPosition);
        $this->assertIsInt($continueTelegramPosition);
        $this->assertTrue($pauseHorizonPosition < $waitForMediaPosition);
        $this->assertTrue($waitForMediaPosition < $stopHorizonPosition);
        $this->assertTrue($stopHorizonPosition < $stopMadelinePosition);
        $this->assertTrue($stopMadelinePosition < $composerInstallPosition);
        $this->assertTrue($composerInstallPosition < $migratePosition);
        $this->assertTrue($migratePosition < $reconcilePosition);
        $this->assertTrue($reconcilePosition < $healthPosition);
        $this->assertTrue($healthPosition < $continueTelegramPosition);
        $this->assertStringContainsString('horizon:terminate', $restartHorizon);
        $this->assertStringContainsString(
            'Waiting for active Telegram media downloads',
            $deploy,
        );
        $this->assertStringContainsString('queues remain paused', $deploy);
        $this->assertStringNotContainsString('composer reinstall amphp/postgres', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-madeline', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-horizon', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-all', $deploy);
        $this->assertSame(0, substr_count($deploy, 'exec horizon php artisan horizon:continue'));
    }

    public function test_cli_workers_override_the_frankenphp_http_healthcheck(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));
        $scheduler = Str::between($compose, "  scheduler:\n", "\n  # Horizon");
        $madeline = Str::between($compose, "  madeline:\n", "\n  # PostgreSQL");

        $this->assertStringContainsString(
            'test: ["CMD", "healthcheck-schedule"]',
            $scheduler,
        );
        $this->assertStringContainsString(
            "pgrep -f '[p]hp artisan telegram:listen' > /dev/null && php artisan telegram:health --no-interaction",
            $madeline,
        );
        $this->assertStringContainsString('start_period: 90s', $madeline);
    }

    public function test_production_does_not_run_the_unused_typesense_service(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));

        $this->assertStringNotContainsString('typesense', $compose);
    }
}
