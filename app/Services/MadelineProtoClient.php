<?php

namespace App\Services;

use Amp\Cancellation;
use App\Contracts\MadelineClient;
use danog\MadelineProto\API;

class MadelineProtoClient implements MadelineClient
{
    private const int MUTE_FOREVER_UNTIL = 2147483647;

    public function __construct(
        private readonly API $api,
        private readonly MadelineIpcCompatibility $ipcCompatibility,
    ) {}

    public function downloadToFile(
        mixed $media,
        string $path,
        ?Cancellation $cancellation = null,
    ): string {
        $download = fn (): string => $this->api->downloadToFile(
            messageMedia: $media,
            file: $path,
            cancellation: $cancellation,
        );

        return $cancellation === null
            ? $download()
            : $this->ipcCompatibility->runWithCancellation($download);
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

    public function canBanChannelParticipants(int|string $channel): bool
    {
        $response = $this->api->channels->getParticipant(
            channel: $channel,
            participant: 'me',
        );
        $participant = $response['participant'];

        return match ($participant['_']) {
            'channelParticipantCreator' => true,
            'channelParticipantAdmin' => $participant['admin_rights']['ban_users'],
            default => false,
        };
    }

    public function getChannelParticipants(int|string $channel, int $offset, int $limit): array
    {
        return $this->api->channels->getParticipants(
            filter: ['_' => 'channelParticipantsSearch', 'q' => ''],
            channel: $channel,
            offset: $offset,
            limit: $limit,
            hash: [],
        );
    }

    public function banChannelParticipant(int|string $channel, int $participantId): void
    {
        $this->api->channels->editBanned(
            banned_rights: [
                '_' => 'chatBannedRights',
                'view_messages' => true,
                'until_date' => 0,
            ],
            channel: $channel,
            participant: $participantId,
        );
    }

    public function unbanChannelParticipant(int|string $channel, int $participantId): void
    {
        $this->api->channels->editBanned(
            banned_rights: [
                '_' => 'chatBannedRights',
                'view_messages' => false,
                'until_date' => 0,
            ],
            channel: $channel,
            participant: $participantId,
        );
    }

    public function joinChannel(int|string $channel): void
    {
        $this->api->channels->joinChannel(channel: $channel);
    }

    public function muteNotifications(int|string $peer): void
    {
        $this->api->account->updateNotifySettings(
            peer: [
                '_' => 'inputNotifyPeer',
                'peer' => $peer,
            ],
            settings: [
                '_' => 'inputPeerNotifySettings',
                'silent' => true,
                'mute_until' => self::MUTE_FOREVER_UNTIL,
            ],
        );
    }
}
