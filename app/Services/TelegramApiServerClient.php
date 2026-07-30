<?php

namespace App\Services;

use App\Contracts\MadelineClient;
use App\Contracts\TelegramMediaClient;
use App\Exceptions\TelegramApiServerException;
use Illuminate\Http\Client\Response;

class TelegramApiServerClient implements MadelineClient, TelegramMediaClient
{
    public function __construct(
        private readonly TelegramApiServer $server,
        private readonly string $session,
    ) {}

    public function downloadToFile(mixed $media, string $path): string
    {
        $response = $this->server
            ->request((int) config('services.telegram.api_server.download_timeout', 330))
            ->sink($path)
            ->post(
                '/api/'.rawurlencode($this->session).'/downloadToResponse',
                ['messageMedia' => $media],
            );

        if (! $response->successful()) {
            throw $this->downloadException($response, $path);
        }

        return $path;
    }

    public function downloadMessageToFile(
        int|string $peer,
        int $messageId,
        string $path,
        bool $previewOnly,
    ): string {
        $method = $previewOnly ? 'getMediaPreview' : 'getMedia';
        $response = $this->server
            ->request((int) config('services.telegram.api_server.download_timeout', 330))
            ->sink($path)
            ->post(
                '/api/'.rawurlencode($this->session).'/'.$method,
                [
                    'peer' => $peer,
                    'id' => $messageId,
                ],
            );

        if (! $response->successful()) {
            throw $this->downloadException($response, $path);
        }

        return $path;
    }

    public function getChannelMessage(int|string $peer, int $messageId): ?array
    {
        $response = $this->call('getMessages', [
            'peer' => $peer,
            'id' => [$messageId],
        ]);
        $messages = is_array($response['messages'] ?? null) ? $response['messages'] : [];

        return collect($messages)->first(
            fn (mixed $message): bool => is_array($message)
                && ($message['_'] ?? null) === 'message'
                && (int) ($message['id'] ?? 0) === $messageId,
        );
    }

    public function getHistory(int|string $peer, int $offsetId, int $limit): array
    {
        return $this->call('messages.getHistory', [
            'peer' => $peer,
            'offset_id' => $offsetId,
            'limit' => $limit,
        ]);
    }

    public function getInfo(mixed $peer): array|string|int
    {
        $response = $this->call('getInfo', ['id' => $peer]);

        return $response['value'] ?? $response;
    }

    public function canBanChannelParticipants(int|string $channel): bool
    {
        $response = $this->call('channels.getParticipant', [
            'channel' => $channel,
            'participant' => 'me',
        ]);
        $participant = is_array($response['participant'] ?? null)
            ? $response['participant']
            : [];

        return match ($participant['_'] ?? null) {
            'channelParticipantCreator' => true,
            'channelParticipantAdmin' => (bool) data_get($participant, 'admin_rights.ban_users', false),
            default => false,
        };
    }

    public function getChannelParticipants(int|string $channel, int $offset, int $limit): array
    {
        return $this->call('channels.getParticipants', [
            'filter' => ['_' => 'channelParticipantsSearch', 'q' => ''],
            'channel' => $channel,
            'offset' => $offset,
            'limit' => $limit,
            'hash' => [],
        ]);
    }

    public function banChannelParticipant(int|string $channel, int $participantId): void
    {
        $this->editBanned($channel, $participantId, true);
    }

    public function unbanChannelParticipant(int|string $channel, int $participantId): void
    {
        $this->editBanned($channel, $participantId, false);
    }

    public function joinChannel(int|string $channel): void
    {
        $this->call('channels.joinChannel', ['channel' => $channel]);
    }

    public function muteNotifications(int|string $peer): void
    {
        $this->call('account.updateNotifySettings', [
            'peer' => [
                '_' => 'inputNotifyPeer',
                'peer' => $peer,
            ],
            'settings' => [
                '_' => 'inputPeerNotifySettings',
                'silent' => true,
                'mute_until' => 2_147_483_647,
            ],
        ]);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function call(string $method, array $parameters = []): array
    {
        return $this->server->call($this->session, $method, $parameters);
    }

    private function editBanned(int|string $channel, int $participantId, bool $banned): void
    {
        $this->call('channels.editBanned', [
            'banned_rights' => [
                '_' => 'chatBannedRights',
                'view_messages' => $banned,
                'until_date' => 0,
            ],
            'channel' => $channel,
            'participant' => $participantId,
        ]);
    }

    private function downloadException(Response $response, string $path): TelegramApiServerException
    {
        $body = is_file($path) ? file_get_contents($path) : false;
        $payload = is_string($body) ? json_decode($body, true) : null;

        return $this->server->exception(
            is_array($payload) ? $payload : [],
            $response->status(),
        );
    }
}
