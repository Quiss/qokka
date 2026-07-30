<?php

namespace Tests\Unit;

use App\Console\Commands\TelegramListenCommand;
use App\Contracts\MadelineClient;
use App\Contracts\TelegramMediaClient;
use App\Services\TelegramApiServerClient;
use App\Services\TelegramApiServerClientFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MadelineIpcCompatibilityTest extends TestCase
{
    public function test_application_factory_creates_only_http_api_clients(): void
    {
        $factory = new ReflectionClass(TelegramApiServerClientFactory::class);

        $this->assertTrue($factory->hasMethod('forAccount'));
        $this->assertFalse($factory->hasMethod('makeIpcClient'));
    }

    public function test_runtime_client_and_listener_have_no_ipc_dependency(): void
    {
        $this->assertTrue(is_a(TelegramApiServerClient::class, MadelineClient::class, true));
        $this->assertTrue(is_a(TelegramApiServerClient::class, TelegramMediaClient::class, true));

        $listenerSource = file_get_contents(
            (new ReflectionClass(TelegramListenCommand::class))->getFileName(),
        );

        $this->assertIsString($listenerSource);
        $this->assertStringNotContainsString('MadelineApiFactory', $listenerSource);
        $this->assertStringNotContainsString('startAndLoopMulti', $listenerSource);
        $this->assertStringNotContainsString('isIpc', $listenerSource);
    }
}
