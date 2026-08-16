<?php

namespace Tests\Feature;

use App\Jobs\DownloadMediaAssetJob;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_editor_media_upload_limits_are_consistent_across_runtime_configuration(): void
    {
        $productionIni = File::get(base_path('docker/production/php/php-production.ini'));
        $sailIni = File::get(base_path('docker/production/php/php.ini'));
        $compose = File::get(base_path('compose.yaml'));

        $this->assertSame(['required', 'file', 'max:512000'], config('livewire.temporary_file_upload.rules'));
        $this->assertSame(30, config('livewire.temporary_file_upload.max_upload_time'));
        $this->assertStringContainsString('upload_max_filesize = 500M', $productionIni);
        $this->assertStringContainsString('post_max_size = 512M', $productionIni);
        $this->assertStringContainsString('max_input_time = 1800', $productionIni);
        $this->assertStringContainsString('upload_max_filesize=500M', $sailIni);
        $this->assertStringContainsString('post_max_size=512M', $sailIni);
        $this->assertStringContainsString(
            './docker/production/php/php.ini:/etc/php/8.5/cli/conf.d/zzz-channelbot.ini',
            $compose,
        );
    }

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
        $compose = File::get(base_path('docker-compose.production.yml'));
        $horizon = Str::between($compose, "  horizon:\n", "\n  # TelegramApiServer");
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
        $prepareApiStoragePosition = strpos($deploy, '$(MAKE) prepare-telegram-api-storage');
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
        $this->assertIsInt($prepareApiStoragePosition);
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
        $this->assertTrue($buildApiPosition < $prepareApiStoragePosition);
        $this->assertTrue($prepareApiStoragePosition < $startApiPosition);
        $this->assertTrue($startApiPosition < $apiHealthPosition);
        $this->assertTrue($apiHealthPosition < $startConsumersPosition);
        $this->assertTrue($startConsumersPosition < $restartHorizonPosition);
        $this->assertTrue($restartHorizonPosition < $requestMissingPosition);
        $this->assertTrue($requestMissingPosition < $continueTelegramPosition);
        $this->assertStringContainsString('horizon:terminate', $restartHorizon);
        $this->assertStringContainsString('restart --timeout 370 horizon', $restartHorizon);
        $this->assertStringContainsString(
            'up -d --no-deps --wait --wait-timeout 180 horizon',
            $restartHorizon,
        );
        $this->assertStringContainsString('[ $$status -le 1 ]', $horizon);
        $this->assertStringContainsString('Deploy failed during $$DEPLOY_STAGE', $deploy);
        $this->assertStringContainsString('queues remain paused', $deploy);
        $this->assertStringNotContainsString('composer reinstall amphp/postgres', $deploy);
        $this->assertStringNotContainsString('stop --timeout 370 horizon', $deploy);
        $this->assertStringNotContainsString(
            'up -d --wait --wait-timeout 180 horizon',
            $deploy,
        );
        $this->assertStringNotContainsString('horizon:clear --queue=media-download-', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-all', $deploy);
        $this->assertSame(0, substr_count($deploy, 'exec horizon php artisan horizon:continue'));
    }

    public function test_telegram_api_storage_is_owned_by_its_runtime_user_before_starting(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $prepareStorage = Str::between(
            $makefile,
            "prepare-telegram-api-storage:\n",
            "\nrestart-telegram-api:",
        );
        $restartTelegramApi = Str::between(
            $makefile,
            "restart-telegram-api: prepare-telegram-api-storage\n",
            "\nrestart-telegram-events:",
        );

        $this->assertStringContainsString(
            'run --rm --no-deps --user 0:0 --entrypoint /bin/sh telegram-api',
            $prepareStorage,
        );
        $this->assertStringContainsString(
            'chown -R "$(UID):$(GID)" /app-host-link/sessions',
            $prepareStorage,
        );
        $this->assertStringContainsString(
            'chmod -R u+rwX,g+rwX /app-host-link/sessions',
            $prepareStorage,
        );
        $this->assertStringContainsString(
            'restart --timeout 120 telegram-api',
            $restartTelegramApi,
        );
    }

    public function test_telegram_api_is_the_only_madeline_session_owner(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));
        $dockerfile = File::get(base_path('docker/production/telegram-api/Dockerfile'));
        $emptyReportPeersPatch = File::get(
            base_path('docker/production/telegram-api/patches/madelineproto-empty-report-peers.patch'),
        );
        $disableCdnDownloadsPatch = File::get(
            base_path('docker/production/telegram-api/patches/madelineproto-disable-cdn-downloads.patch'),
        );
        $disablePreparedStatementsPatch = File::get(
            base_path('docker/production/telegram-api/patches/async-orm-disable-prepared-statements.patch'),
        );
        $scheduler = Str::between($compose, "  scheduler:\n", "\n  # Horizon");
        $telegramApi = Str::between($compose, "  telegram-api:\n", "\n  # Receives raw");
        $telegramEvents = Str::between($compose, "  telegram-events:\n", "\n  # Executes media");
        $telegramOwner = Str::between($compose, "  telegram-owner:\n", "\n  # PostgreSQL");
        $composerInstallPosition = strpos($dockerfile, 'RUN composer install');
        $copyPatchPosition = strpos(
            $dockerfile,
            'COPY docker/production/telegram-api/patches/madelineproto-empty-report-peers.patch',
        );
        $applyPatchPosition = strpos($dockerfile, 'RUN patch --strip=1');

        $this->assertStringContainsString(
            'test: ["CMD", "healthcheck-schedule"]',
            $scheduler,
        );
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($copyPatchPosition);
        $this->assertIsInt($applyPatchPosition);
        $this->assertTrue($composerInstallPosition < $copyPatchPosition);
        $this->assertTrue($copyPatchPosition < $applyPatchPosition);
        $this->assertStringContainsString(
            'FROM xtrime/telegram-api-server:2.7.2',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'patches/madelineproto-empty-report-peers.patch',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'patches/madelineproto-disable-cdn-downloads.patch',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'patches/async-orm-disable-prepared-statements.patch',
            $dockerfile,
        );
        $this->assertStringContainsString(
            'if ($userOrId === [])',
            $emptyReportPeersPatch,
        );
        $this->assertStringContainsString('return [];', $emptyReportPeersPatch);
        $this->assertStringContainsString(
            "'cdn_supported' => false",
            $disableCdnDownloadsPatch,
        );
        $this->assertStringContainsString(
            '-        return $this->db->prepare($sql)->execute($params);',
            $disablePreparedStatementsPatch,
        );
        $this->assertStringContainsString(
            '+        return $this->db->execute($sql, $params);',
            $disablePreparedStatementsPatch,
        );
        $this->assertStringContainsString('RESUME_ON_ERROR: 1', $telegramApi);
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
