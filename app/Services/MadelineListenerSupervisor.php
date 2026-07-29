<?php

namespace App\Services;

use App\Contracts\MadelineListenerSession;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler;
use InvalidArgumentException;
use LogicException;

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

        foreach ($sessions as $session) {
            $session->assertCanTakeOver();
        }

        foreach ($sessions as $session) {
            $session->prepareForTakeover();
        }

        $this->startAndLoopMulti($sessions, $eventHandler);
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
}
