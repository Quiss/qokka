<?php

namespace App\Services;

use App\Contracts\MadelineListenerSession;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use InvalidArgumentException;
use LogicException;

use function Amp\delay;

class MadelineListenerSupervisor
{
    /**
     * @param  array<string, MadelineListenerSession>  $sessions
     * @param  class-string<EventHandler>  $eventHandler
     */
    public function run(array $sessions, string $eventHandler): void
    {
        if ($sessions === []) {
            throw new InvalidArgumentException('At least one MadelineProto listener session is required.');
        }

        $sessionsToStart = $this->sessionsWithoutRunningEventHandler($sessions);

        if ($sessionsToStart !== []) {
            $this->startAndLoopMulti($sessionsToStart, $eventHandler);

            return;
        }

        while (true) {
            $this->pause();
            $sessionsToStart = $this->sessionsWithoutRunningEventHandler($sessions);

            if ($sessionsToStart === []) {
                continue;
            }

            $this->startAndLoopMulti($sessionsToStart, $eventHandler);

            return;
        }
    }

    /**
     * @param  array<string, MadelineListenerSession>  $sessions
     * @return array<string, MadelineListenerSession>
     */
    private function sessionsWithoutRunningEventHandler(array $sessions): array
    {
        return array_filter(
            $sessions,
            static fn (MadelineListenerSession $session): bool => ! $session->hasRunningEventHandler(),
        );
    }

    /**
     * @param  non-empty-array<string, MadelineListenerSession>  $sessions
     * @param  class-string<EventHandler>  $eventHandler
     */
    protected function startAndLoopMulti(array $sessions, string $eventHandler): void
    {
        $instances = array_map(
            static function (MadelineListenerSession $session): API {
                if (! $session instanceof MadelineProtoListenerSession) {
                    throw new LogicException('The production listener requires MadelineProto listener sessions.');
                }

                return $session->api();
            },
            $sessions,
        );

        API::startAndLoopMulti($instances, $eventHandler);
    }

    protected function pause(): void
    {
        delay(15);
    }
}
