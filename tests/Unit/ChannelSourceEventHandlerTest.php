<?php

namespace Tests\Unit;

use App\Telegram\ChannelSourceEventHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ChannelSourceEventHandlerTest extends TestCase
{
    public function test_subscription_refresh_is_forked_during_startup(): void
    {
        $onStart = new ReflectionMethod(ChannelSourceEventHandler::class, 'onStart');
        $sourceLines = file($onStart->getFileName());

        $this->assertIsArray($sourceLines);

        $source = implode('', array_slice(
            $sourceLines,
            $onStart->getStartLine() - 1,
            $onStart->getEndLine() - $onStart->getStartLine() + 1,
        ));
        $normalizedSource = preg_replace('/\s+/', ' ', $source);

        $this->assertIsString($normalizedSource);
        $this->assertStringContainsString(
            '$this->callFork(function (): void { $this->refreshSubscriptions(); })->ignore();',
            $normalizedSource,
        );
        $this->assertStringContainsString(
            'app(TelegramOwnerCommandPump::class)->run(',
            $normalizedSource,
        );
        $this->assertSame(2, substr_count($normalizedSource, '})->ignore();'));
    }
}
