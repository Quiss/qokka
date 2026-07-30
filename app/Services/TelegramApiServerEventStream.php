<?php

namespace App\Services;

use Amp\Websocket\Client\WebsocketHandshake;
use JsonException;
use RuntimeException;
use Throwable;

use function Amp\delay;
use function Amp\Websocket\Client\connect;

class TelegramApiServerEventStream
{
    public function __construct(
        private readonly TelegramApiServerUpdateHandler $updateHandler,
    ) {}

    /** @param callable(Throwable, int): void|null $onError */
    public function run(?callable $onError = null): never
    {
        $attempt = 0;

        while (true) {
            try {
                $handshake = (new WebsocketHandshake(
                    (string) config('services.telegram.api_server.websocket_url'),
                ))->withTcpConnectTimeout(
                    (float) config('services.telegram.api_server.connect_timeout', 5),
                );
                $connection = connect($handshake);
                $attempt = 0;

                while ($message = $connection->receive()) {
                    $this->updateHandler->handle(
                        $this->decode($message->buffer()),
                    );
                }

                throw new RuntimeException('TelegramApiServer закрыл WebSocket updates.');
            } catch (Throwable $exception) {
                $attempt++;

                if ($onError !== null) {
                    $onError($exception, $attempt);
                }

                delay(min(30, max(1, $attempt * 2)));
            }
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $payload): array
    {
        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($event) ? $event : [];
    }
}
