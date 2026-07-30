<?php

namespace Tests\Unit;

use App\Contracts\MadelineClient;
use App\Services\MadelineApiFactory;
use App\Services\MadelineProtoClient;
use App\Telegram\ChannelSourceEventHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MadelineIpcCompatibilityTest extends TestCase
{
    public function test_application_factory_exposes_only_the_session_owner_entrypoint(): void
    {
        $factory = new ReflectionClass(MadelineApiFactory::class);

        $this->assertTrue($factory->hasMethod('makeOwner'));
        $this->assertFalse($factory->hasMethod('makeIpcClient'));
    }

    public function test_only_the_madeline_event_handler_implements_the_rpc_client_contract(): void
    {
        $this->assertTrue(is_a(ChannelSourceEventHandler::class, MadelineClient::class, true));
        $this->assertFalse(class_exists(MadelineProtoClient::class));
    }
}
