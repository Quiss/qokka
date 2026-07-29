<?php

namespace Tests\Unit;

use App\Contracts\MadelineListenerSession;
use App\Services\MadelineListenerSupervisor;
use App\Telegram\ChannelSourceEventHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MadelineListenerSupervisorTest extends TestCase
{
    public function test_it_prepares_every_session_and_starts_them_together(): void
    {
        $firstSession = new MadelineListenerSessionFake;
        $secondSession = new MadelineListenerSessionFake;
        $supervisor = $this->fakeSupervisor();

        $supervisor->run([
            'first' => $firstSession,
            'second' => $secondSession,
        ], ChannelSourceEventHandler::class);

        $this->assertSame(['first', 'second'], array_keys($supervisor->startedSessions));
        $this->assertSame(1, $firstSession->validations);
        $this->assertSame(1, $firstSession->preparations);
        $this->assertSame(1, $secondSession->validations);
        $this->assertSame(1, $secondSession->preparations);
    }

    public function test_it_validates_every_session_before_preparing_any_takeover(): void
    {
        $firstSession = new MadelineListenerSessionFake;
        $blockedSession = new MadelineListenerSessionFake(
            takeoverException: new RuntimeException('Session is owned by another process.'),
        );
        $supervisor = $this->fakeSupervisor();

        try {
            $supervisor->run([
                'first' => $firstSession,
                'blocked' => $blockedSession,
            ], ChannelSourceEventHandler::class);
            $this->fail('Expected takeover validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Session is owned by another process.', $exception->getMessage());
        }

        $this->assertSame(0, $firstSession->preparations);
        $this->assertSame([], $supervisor->startedSessions);
    }

    public function test_it_requires_at_least_one_session(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fakeSupervisor()->run([], ChannelSourceEventHandler::class);
    }

    private function fakeSupervisor(): MadelineListenerSupervisorFake
    {
        return new MadelineListenerSupervisorFake;
    }
}

class MadelineListenerSupervisorFake extends MadelineListenerSupervisor
{
    /** @var array<string, MadelineListenerSession> */
    public array $startedSessions = [];

    protected function startAndLoopMulti(array $sessions, string $eventHandler): void
    {
        $this->startedSessions = $sessions;
    }
}

class MadelineListenerSessionFake implements MadelineListenerSession
{
    public int $validations = 0;

    public int $preparations = 0;

    public function __construct(private readonly ?RuntimeException $takeoverException = null) {}

    public function isRemote(): bool
    {
        return false;
    }

    public function assertCanTakeOver(): void
    {
        $this->validations++;

        if ($this->takeoverException !== null) {
            throw $this->takeoverException;
        }
    }

    public function prepareForTakeover(): void
    {
        $this->preparations++;
    }
}
