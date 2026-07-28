<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionDeploymentConfigurationTest extends TestCase
{
    public function test_deploy_pauses_and_restores_publish_and_telegram_queues(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");

        $this->assertStringContainsString(
            'queue:pause $(PUBLISH_QUEUE)',
            $deploy,
        );
        $this->assertStringContainsString(
            'queue:pause $(TELEGRAM_QUEUE)',
            $deploy,
        );
        $this->assertSame(2, substr_count($deploy, 'queue:continue $(PUBLISH_QUEUE)'));
        $this->assertSame(2, substr_count($deploy, 'queue:continue $(TELEGRAM_QUEUE)'));
    }

    public function test_deploy_gracefully_terminates_horizon_without_stopping_worker_containers(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");
        $restartHorizon = Str::between(
            $makefile,
            "restart-horizon:\n",
            "\n# Restart Scheduler",
        );

        $pauseHorizonPosition = strpos($deploy, 'exec horizon php artisan horizon:pause');
        $composerInstallPosition = strpos($deploy, 'composer install');
        $reconcilePosition = strpos($deploy, '$(MAKE) reconcile-containers');
        $restartHorizonPosition = strpos($deploy, '$(MAKE) restart-horizon');
        $continueTelegramPosition = strrpos($deploy, 'queue:continue $(TELEGRAM_QUEUE)');

        $this->assertIsInt($pauseHorizonPosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($reconcilePosition);
        $this->assertIsInt($restartHorizonPosition);
        $this->assertIsInt($continueTelegramPosition);
        $this->assertTrue($pauseHorizonPosition < $composerInstallPosition);
        $this->assertTrue($composerInstallPosition < $reconcilePosition);
        $this->assertTrue($reconcilePosition < $restartHorizonPosition);
        $this->assertTrue($restartHorizonPosition < $continueTelegramPosition);
        $this->assertStringContainsString('horizon:terminate', $restartHorizon);
        $this->assertStringNotContainsString('$(PRODUCTION_COMPOSE) stop', $deploy);
        $this->assertStringNotContainsString('composer reinstall amphp/postgres', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-madeline', $deploy);
        $this->assertStringNotContainsString('$(MAKE) restart-all', $deploy);
        $this->assertSame(1, substr_count($deploy, 'exec horizon php artisan horizon:continue'));
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
            "test: [\"CMD-SHELL\", \"pgrep -f '[p]hp artisan telegram:listen' > /dev/null\"]",
            $madeline,
        );
    }

    public function test_production_does_not_run_the_unused_typesense_service(): void
    {
        $compose = File::get(base_path('docker-compose.production.yml'));

        $this->assertStringNotContainsString('typesense', $compose);
    }
}
