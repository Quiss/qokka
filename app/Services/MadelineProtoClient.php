<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use danog\MadelineProto\API;

class MadelineProtoClient implements MadelineClient
{
    public function __construct(private readonly API $api) {}

    public function downloadToFile(mixed $media, string $path): string
    {
        return $this->api->downloadToFile($media, $path);
    }

    public function getChannelMessage(int|string $peer, int $messageId): ?array
    {
        $response = $this->api->channels->getMessages(
            channel: $peer,
            id: [$messageId],
        );
        $message = collect($response['messages'] ?? [])
            ->first(fn (array $message): bool => ($message['_'] ?? null) === 'message'
                && (int) ($message['id'] ?? 0) === $messageId);

        return $message;
    }

    public function getHistory(int|string $peer, int $offsetId, int $limit): array
    {
        return $this->api->messages->getHistory(
            peer: $peer,
            offset_id: $offsetId,
            limit: $limit,
        );
    }

    public function getInfo(int|string $peer): array
    {
        return $this->api->getInfo($peer);
    }

    public function joinChannel(int|string $channel): void
    {
        $this->api->channels->joinChannel(channel: $channel);
    }
}
