<?php

namespace App\Contracts;

use Amp\Cancellation;

interface MadelineClient
{
    public function downloadToFile(
        mixed $media,
        string $path,
        ?Cancellation $cancellation = null,
    ): string;

    /**
     * @return array<string, mixed>|null
     */
    public function getChannelMessage(
        int|string $peer,
        int $messageId,
        ?Cancellation $cancellation = null,
    ): ?array;

    /**
     * @return array<string, mixed>
     */
    public function getHistory(int|string $peer, int $offsetId, int $limit): array;

    /**
     * @return array<string, mixed>
     */
    public function getInfo(int|string $peer): array;

    public function canBanChannelParticipants(int|string $channel): bool;

    /**
     * @return array<string, mixed>
     */
    public function getChannelParticipants(int|string $channel, int $offset, int $limit): array;

    public function banChannelParticipant(int|string $channel, int $participantId): void;

    public function unbanChannelParticipant(int|string $channel, int $participantId): void;

    public function joinChannel(int|string $channel): void;

    public function muteNotifications(int|string $peer): void;
}
