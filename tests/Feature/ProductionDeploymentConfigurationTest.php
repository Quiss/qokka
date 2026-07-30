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

    public function test_horizon_has_no_media_download_supervisor_or_telegram_api_dependency(): void
    {
        $telegramSupervisor = config('horizon.environments.production.supervisor-telegram');
        $job = new DownloadMediaAssetJob(1);
        $compose = File::get(base_path('docker-compose.production.yml'));
        $horizon = Str::between($compose, "  horizon:\n", "\n  # TelegramApiServer");

        $this->assertSame(['telegram'], $telegramSupervisor['queue']);
        $this->assertSame(3, $telegramSupervisor['maxProcesses']);
        $this->assertArrayNotHasKey(
            'supervisor-media-download',
            config('horizon.environments.production'),
        );
        $this->assertSame(1, $job->tries);
        $this->assertFalse(method_exists($job, 'retryUntil'));
        $this->assertFalse(method_exists($job, 'middleware'));
        $this->assertStringNotContainsString('telegram-api:', $horizon);
    }

    public function test_legacy_media_job_has_no_retry_or_ipc_surface(): void
    {
        $job = new DownloadMediaAssetJob(1);

        $this->assertSame(1, $job->tries);
        $this->assertFalse(property_exists($job, 'backoff'));
        $this->assertFalse(property_exists($job, 'timeout'));
        $this->assertFalse(method_exists($job, 'retryUntil'));
    }

    public function test_deploy_gracefully_restarts_horizon_after_code_update_and_waits_for_telegram_api(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");
        $restartHorizon = Str::between(
            $makefile,
            "restart-horizon:\n",
            "\n# Restart Scheduler",
        );

        $pauseHorizonPosition = strpos($deploy, 'exec horizon php artisan horizon:pause');
        $stopOwnerPosition = strpos($deploy, 'stop --timeout 370 telegram-owner');
        $stopEventsPosition = strpos($deploy, 'stop --timeout 30 telegram-events');
        $composerInstallPosition = strpos($deploy, 'composer install');
        $migratePosition = strpos($deploy, 'artisan migrate --force');
        $buildApiPosition = strpos($deploy, 'build --pull --no-cache telegram-api');
        $startApiPosition = strpos($deploy, 'up -d --no-build --wait --wait-timeout 240 telegram-api');
        $apiHealthPosition = strpos($deploy, 'telegram:api:health');
        $startConsumersPosition = strpos($deploy, 'telegram-events telegram-owner');
        $restartHorizonPosition = strpos($deploy, '$(MAKE) restart-horizon');
        $requestMissingPosition = strpos($deploy, 'telegram:media:request-missing');
        $continueTelegramPosition = strrpos($deploy, 'queue:continue $(TELEGRAM_QUEUE)');

        $this->assertIsInt($pauseHorizonPosition);
        $this->assertIsInt($stopOwnerPosition);
        $this->assertIsInt($stopEventsPosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($migratePosition);
        $this->assertIsInt($buildApiPosition);
        $this->assertIsInt($startApiPosition);
        $this->assertIsInt($apiHealthPosition);
        $this->assertIsInt($startConsumersPosition);
        $this->assertIsInt($restartHorizonPosition);
        $this->assertIsInt($requestMissingPosition);
        $this->assertIsInt($continueTelegramPosition);
        $this->assertTrue($pauseHorizonPosition < $stopOwnerPosition);
        $this->assertTrue($stopOwnerPosition < $stopEventsPosition);
        $this->assertTrue($stopEventsPosition < $composerInstallPosition);
        $this->assertTrue($composerInstallPosition < $migratePosition);
        $this->assertTrue($migratePosition < $buildApiPosition);
        $this->assertTrue($buildApiPosition < $startApiPosition);
        $this->assertTrue($startApiPosition < $apiHealthPosition);
        $this->assertTrue($apiHealthPosition < $startConsumersPosition);
        $this->assertTrue($startConsumersPosition < $restartHorizonPosition);
        $this->assertTrue($restartHorizonPosition < $requestMissingPosition);
        $this->assertTrue($requestMissingPosition < $continueTelegramPosition);
        $this->assertStringContainsString('horizon:terminate', $restartHorizon);
        $this->assertStringContainsString('queues remain paused', $deploy);
        $this->assertStringNotContainsString('composer reinstall amphp/postgres', $deploy);
        $this->assertStringNotContainsString('stop --timeout 370 horizon', $deploy);
        $this->assertStringNotContainsString('horizon:clear --queue=media-download-', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-all', $deploy);
        $this->assertSame(0, substr_count($deploy, 'exec horizon php artisan horizon:continue'));
    }

    public function test_telegram_api_is_the_only_madeline_session_owner(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));
        $scheduler = Str::between($compose, "  scheduler:\n", "\n  # Horizon");
        $telegramApi = Str::between($compose, "  telegram-api:\n", "\n  # Receives raw");
        $telegramEvents = Str::between($compose, "  telegram-events:\n", "\n  # Executes media");
        $telegramOwner = Str::between($compose, "  telegram-owner:\n", "\n  # PostgreSQL");

        $this->assertStringContainsString(
            'test: ["CMD", "healthcheck-schedule"]',
            $scheduler,
        );
        $this->assertStringContainsString(
            'FROM xtrime/telegram-api-server:2.7.2',
            File::get(base_path('docker/production/telegram-api/Dockerfile')),
        );
        $this->assertStringContainsString(
            './storage/app/telegram-api-server/sessions:/app-host-link/sessions',
            $telegramApi,
        );
        $this->assertStringNotContainsString('TELEGRAM_API_SERVER_REF', $compose);
        $this->assertStringContainsString('telegram:listen', $telegramEvents);
        $this->assertStringContainsString('telegram:owner:work', $telegramOwner);
        $this->assertStringNotContainsString("\n  madeline:", $compose);
    }

    public function test_production_does_not_run_the_unused_typesense_service(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));

        $this->assertStringNotContainsString('typesense', $compose);
    }
}
