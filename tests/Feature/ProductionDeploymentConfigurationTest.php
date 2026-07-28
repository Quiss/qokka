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

    public function test_deploy_reapplies_dependency_patches_while_workers_are_stopped(): void
    {
        $makefile = File::get(base_path('Makefile'));
        $deploy = Str::between($makefile, "deploy:\n", "\ndeploy-full:");

        $pauseHorizonPosition = strpos($deploy, 'exec horizon php artisan horizon:pause');
        $stopHorizonPosition = strpos($deploy, 'stop --timeout 370 horizon');
        $stopMadelinePosition = strpos($deploy, 'stop --timeout 120 scheduler madeline');
        $composerInstallPosition = strpos($deploy, 'composer install');
        $composerReinstallPosition = strpos($deploy, 'composer reinstall amphp/postgres');
        $reconcilePosition = strpos($deploy, '$(MAKE) reconcile-containers');

        $this->assertIsInt($pauseHorizonPosition);
        $this->assertIsInt($stopHorizonPosition);
        $this->assertIsInt($stopMadelinePosition);
        $this->assertIsInt($composerInstallPosition);
        $this->assertIsInt($composerReinstallPosition);
        $this->assertIsInt($reconcilePosition);
        $this->assertTrue($pauseHorizonPosition < $stopHorizonPosition);
        $this->assertTrue($stopHorizonPosition < $stopMadelinePosition);
        $this->assertTrue($stopMadelinePosition < $composerInstallPosition);
        $this->assertTrue($composerInstallPosition < $composerReinstallPosition);
        $this->assertTrue($composerReinstallPosition < $reconcilePosition);
        $this->assertStringContainsString(
            'up -d $(DEPLOY_WORKER_SERVICES) >/dev/null 2>&1 || true',
            $deploy,
        );
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
