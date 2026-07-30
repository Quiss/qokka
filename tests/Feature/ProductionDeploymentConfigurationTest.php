<?php

namespace Tests\Feature;

use App\Jobs\DownloadMediaAssetJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

        $this->assertStringNotContainsString('MEDIA_DOWNLOAD_', $deploy);
    }

    public function test_horizon_has_no_media_download_supervisor_or_madeline_dependency(): void
    {
        $telegramSupervisor = config('horizon.environments.production.supervisor-telegram');
        $job = new DownloadMediaAssetJob(1);
        $compose = File::get(base_path('docker-compose.production.yml'));
        $horizon = Str::between($compose, "  horizon:\n", "\n  # MadelineProto");

        $this->assertSame(['telegram'], $telegramSupervisor['queue']);
        $this->assertSame(3, $telegramSupervisor['maxProcesses']);
        $this->assertArrayNotHasKey(
            'supervisor-media-download',
            config('horizon.environments.production'),
        );
        $this->assertSame(1, $job->tries);
        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertFalse(method_exists($job, 'middleware'));
        $this->assertStringNotContainsString('madeline:', $horizon);
    }

    public function test_legacy_media_job_has_no_retry_or_ipc_surface(): void
    {
        $job = new DownloadMediaAssetJob(1);

        $this->assertSame(1, $job->tries);
        $this->assertFalse(property_exists($job, 'backoff'));
        $this->assertFalse(property_exists($job, 'timeout'));
        $this->assertFalse(method_exists($job, 'retryUntil'));
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
        $stopHorizonPosition = strpos($deploy, 'stop --timeout 370 horizon');
        $stopMadelinePosition = strpos($deploy, 'stop --timeout 120 madeline');
        $composerInstallPosition = strpos($deploy, 'composer install');
        $migratePosition = strpos($deploy, 'artisan migrate --force');
        $requestMissingPosition = strpos($deploy, 'telegram:media:request-missing');
        $clearHighPosition = strpos($deploy, 'horizon:clear --queue=media-download-high');
        $startMadelinePosition = strpos($deploy, 'up -d --wait --wait-timeout 180 madeline');
        $healthPosition = strpos($deploy, 'exec madeline php artisan telegram:health');
        $startHorizonPosition = strpos($deploy, 'up -d --wait --wait-timeout 180 horizon');
        $continueTelegramPosition = strrpos($deploy, 'queue:continue $(TELEGRAM_QUEUE)');

        $this->assertIsInt($pauseHorizonPosition);
        $this->assertIsInt($stopHorizonPosition);
        $this->assertIsInt($stopMadelinePosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($migratePosition);
        $this->assertIsInt($requestMissingPosition);
        $this->assertIsInt($clearHighPosition);
        $this->assertIsInt($startMadelinePosition);
        $this->assertIsInt($healthPosition);
        $this->assertIsInt($startHorizonPosition);
        $this->assertIsInt($continueTelegramPosition);
        $this->assertTrue($pauseHorizonPosition < $stopHorizonPosition);
        $this->assertTrue($stopHorizonPosition < $stopMadelinePosition);
        $this->assertTrue($stopMadelinePosition < $composerInstallPosition);
        $this->assertTrue($composerInstallPosition < $migratePosition);
        $this->assertTrue($migratePosition < $requestMissingPosition);
        $this->assertTrue($requestMissingPosition < $clearHighPosition);
        $this->assertTrue($clearHighPosition < $startMadelinePosition);
        $this->assertTrue($startMadelinePosition < $healthPosition);
        $this->assertTrue($healthPosition < $startHorizonPosition);
        $this->assertTrue($healthPosition < $continueTelegramPosition);
        $this->assertStringContainsString('horizon:terminate', $restartHorizon);
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
