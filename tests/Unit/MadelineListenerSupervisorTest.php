<?php

namespace Tests\Unit;

use App\Contracts\MadelineListenerSession;
use App\Services\MadelineListenerSupervisor;
use App\Telegram\ChannelSourceEventHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MadelineListenerSupervisorTest extends TestCase
{
    public function test_it_starts_only_sessions_without_a_running_event_handler(): void
    {
        $runningSession = $this->fakeSession([true]);
        $stoppedSession = $this->fakeSession([false]);
        $supervisor = $this->fakeSupervisor();

        $supervisor->run([
            'running' => $runningSession,
            'stopped' => $stoppedSession,
        ], ChannelSourceEventHandler::class);

        $this->assertSame(['stopped'], array_keys($supervisor->startedSessions));
        $this->assertSame(0, $supervisor->pauses);
    }

    public function test_it_monitors_remote_handlers_and_takes_over_when_one_stops(): void
    {
        $session = $this->fakeSession([true, false]);
        $supervisor = $this->fakeSupervisor();

        $supervisor->run(['account' => $session], ChannelSourceEventHandler::class);

        $this->assertSame(['account'], array_keys($supervisor->startedSessions));
        $this->assertSame(1, $supervisor->pauses);
    }

    public function test_it_requires_at_least_one_session(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fakeSupervisor()->run([], ChannelSourceEventHandler::class);
    }

    /**
     * @param  non-empty-list<bool>  $states
     */
    private function fakeSession(array $states): MadelineListenerSession
    {
        return new MadelineListenerSessionFake($states);
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

    public int $pauses = 0;

    protected function startAndLoopMulti(array $sessions, string $eventHandler): void
    {
        $this->startedSessions = $sessions;
    }

    protected function pause(): void
    {
        $this->pauses++;
    }
}

class MadelineListenerSessionFake implements MadelineListenerSession
{
    /** @var non-empty-list<bool> */
    private array $states;

    private int $stateIndex = 0;

    /** @param non-empty-list<bool> $states */
    public function __construct(array $states)
    {
        $this->states = $states;
    }

    public function hasRunningEventHandler(): bool
    {
        $state = $this->states[min($this->stateIndex, count($this->states) - 1)];
        $this->stateIndex++;

        return $state;
    }
}
