<?php

namespace App\Contracts;

interface MadelineClient
{
    public function downloadToFile(mixed $media, string $path): string;

    /**
     * @return array<string, mixed>|null
     */
    public function getChannelMessage(int|string $peer, int $messageId): ?array;

    /**
     * @return array<string, mixed>
     */
    public function getHistory(int|string $peer, int $offsetId, int $limit): array;

    /**
     * @return array<string, mixed>
     */
    public function getInfo(int|string $peer): array;

    public function joinChannel(int|string $channel): void;
}
