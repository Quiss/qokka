<?php

declare(strict_types=1);

namespace App\Telegram;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use App\Contracts\MadelineClient;
use App\Services\MadelineOwnerLease;
use App\Services\TelegramOwnerCommandPump;
use danog\MadelineProto\EventHandler\Attributes\Cron;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Channel\MessageForwards;
use danog\MadelineProto\EventHandler\Channel\MessageViewsChanged;
use danog\MadelineProto\EventHandler\Delete\DeleteChannelMessages;
use danog\MadelineProto\EventHandler\Media as TelegramMedia;
use danog\MadelineProto\EventHandler\Media\DocumentPhoto;
use danog\MadelineProto\EventHandler\Media\Gif;
use danog\MadelineProto\EventHandler\Media\Photo;
use danog\MadelineProto\EventHandler\Media\Video;
use danog\MadelineProto\EventHandler\Message\ChannelMessage;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ChannelSourceEventHandler extends SimpleEventHandler implements MadelineClient
{
    /** @var list<int> */
    private array $allowedPeerIds = [];

    private int $subscriptionsRefreshedAt = 0;

    private int $ownerHeartbeatAt = 0;

    private static ?HttpClient $bridgeHttpClient = null;

    public function onStart(): void
    {
        $this->heartbeatOwner();
        $this->callFork(function (): void {
            $this->refreshSubscriptions();
        })->ignore();
        $this->callFork(function (): void {
            app(TelegramOwnerCommandPump::class)->run(
                $this->telegramAccountUuid(),
                $this,
            );
        })->ignore();
    }

    #[Cron(period: 5.0)]
    public function heartbeatOwner(): void
    {
        $now = time();
        $heartbeatInterval = max(
            5,
            (int) config('services.telegram.owner_heartbeat_seconds', 15),
        );

        if ($this->ownerHeartbeatAt !== 0 && ($now - $this->ownerHeartbeatAt) < $heartbeatInterval) {
            return;
        }

        app(MadelineOwnerLease::class)->heartbeat($this->telegramAccountUuid());
        $this->ownerHeartbeatAt = $now;
    }

    #[Cron(period: 60.0)]
    public function refreshSubscriptions(): void
    {
        try {
            $result = $this->postJson(
                (string) config('services.telegram.subscriptions_url'),
                ['telegram_account_uuid' => $this->telegramAccountUuid()],
            );
            $this->allowedPeerIds = array_values(array_map(
                static fn (mixed $id): int => (int) $id,
                is_array($result['peer_ids'] ?? null) ? $result['peer_ids'] : [],
            ));
            $this->subscriptionsRefreshedAt = time();
        } catch (Throwable $exception) {
            $this->logger('Не удалось обновить подписки источников: '.$exception->getMessage());
        }
    }

    #[Handler]
    public function handleChannelMessage(ChannelMessage $message): void
    {
        if ($message->out || ! $this->isAssigned($message->chatId)) {
            return;
        }

        $payload = [
            'telegram_account_uuid' => $this->telegramAccountUuid(),
            'event_type' => $message->editDate === null ? 'message' : 'edit',
            'peer_id' => $message->chatId,
            'message_id' => $message->id,
            'grouped_id' => $message->groupedId === null ? null : (string) $message->groupedId,
            'posted_at' => date(DATE_ATOM, $message->date),
            'text' => $message->message,
            'metrics' => array_filter([
                'views' => $message->views,
                'forwards' => $message->forwards,
            ], static fn (mixed $value): bool => $value !== null),
            'media' => $this->storeMedia($message),
        ];

        $this->forward($payload);
    }

    #[Handler]
    public function handleMessageViewsChanged(MessageViewsChanged $update): void
    {
        $this->forwardMetrics($update->chatId, $update->id, ['views' => $update->views]);
    }

    #[Handler]
    public function handleMessageForwardsChanged(MessageForwards $update): void
    {
        $this->forwardMetrics($update->chatId, $update->id, ['forwards' => $update->forwards]);
    }

    #[Handler]
    public function handleDeletedChannelMessages(DeleteChannelMessages $update): void
    {
        if (! $this->isAssigned($update->chatId)) {
            return;
        }

        foreach ($update->ids as $messageId) {
            $this->forward([
                'telegram_account_uuid' => $this->telegramAccountUuid(),
                'event_type' => 'delete',
                'peer_id' => $update->chatId,
                'message_id' => $messageId,
                'posted_at' => date(DATE_ATOM),
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function storeMedia(ChannelMessage $message): array
    {
        $media = $message->media;

        if ($media === null) {
            return [];
        }

        $type = match (true) {
            $media instanceof Photo, $media instanceof DocumentPhoto => 'photo',
            $media instanceof Video => 'video',
            $media instanceof Gif => 'animation',
            default => 'document',
        };
        $extension = ltrim($media->fileExt ?: '', '.') ?: 'bin';
        $externalId = $this->mediaExternalId($media);
        $thumbnail = collect($media->thumbs)
            ->filter(fn (array $thumb): bool => filled($thumb['type'] ?? null))
            ->sortByDesc(fn (array $thumb): int => (int) ($thumb['size'] ?? 0))
            ->first();
        $metadata = array_filter([
            'bot_api_file_id' => $media->botApiFileId,
            'telegram_media_id' => $media->location['id'] ?? null,
            'file_name' => $media->fileName,
            'thumbnail_type' => is_array($thumbnail) ? $thumbnail['type'] : null,
            'width' => property_exists($media, 'width') ? $media->width : null,
            'height' => property_exists($media, 'height') ? $media->height : null,
            'duration' => property_exists($media, 'duration') ? $media->duration : null,
            'supports_streaming' => property_exists($media, 'supportsStreaming')
                ? $media->supportsStreaming
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        return [[
            'type' => $type,
            'external_id' => $externalId,
            'mime_type' => $media->mimeType,
            'size_bytes' => $media->size,
            'metadata' => array_merge($metadata, ['file_extension' => $extension]),
        ]];
    }

    private function mediaExternalId(TelegramMedia $media): string
    {
        $telegramMediaId = $media->location['id'] ?? null;

        if (is_int($telegramMediaId) || is_string($telegramMediaId)) {
            $prefix = $media instanceof Photo ? 'photo' : 'document';

            return $prefix.':'.$telegramMediaId;
        }

        return $media->botApiFileUniqueId;
    }

    public function getChannelMessage(int|string $peer, int $messageId): ?array
    {
        $response = $this->channels->getMessages(
            channel: $peer,
            id: [$messageId],
        );

        return collect($response['messages'] ?? [])
            ->first(fn (array $message): bool => ($message['_'] ?? null) === 'message'
                && (int) ($message['id'] ?? 0) === $messageId);
    }

    public function getHistory(int|string $peer, int $offsetId, int $limit): array
    {
        return $this->messages->getHistory(
            peer: $peer,
            offset_id: $offsetId,
            limit: $limit,
        );
    }

    public function canBanChannelParticipants(int|string $channel): bool
    {
        $response = $this->channels->getParticipant(
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
        return $this->channels->getParticipants(
            filter: ['_' => 'channelParticipantsSearch', 'q' => ''],
            channel: $channel,
            offset: $offset,
            limit: $limit,
            hash: [],
        );
    }

    public function banChannelParticipant(int|string $channel, int $participantId): void
    {
        $this->channels->editBanned(
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
        $this->channels->editBanned(
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
        $this->channels->joinChannel(channel: $channel);
    }

    public function muteNotifications(int|string $peer): void
    {
        $this->account->updateNotifySettings(
            peer: [
                '_' => 'inputNotifyPeer',
                'peer' => $peer,
            ],
            settings: [
                '_' => 'inputPeerNotifySettings',
                'silent' => true,
                'mute_until' => 2147483647,
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function forward(array $payload): void
    {
        $this->postJson((string) config('services.telegram.bridge_url'), $payload);
    }

    /** @param array<string, int> $metrics */
    private function forwardMetrics(int $peerId, int $messageId, array $metrics): void
    {
        $peerId = $this->normalizeChannelId($peerId);

        if (! $this->isAssigned($peerId)) {
            return;
        }

        $this->forward([
            'telegram_account_uuid' => $this->telegramAccountUuid(),
            'event_type' => 'metrics',
            'peer_id' => $peerId,
            'message_id' => $messageId,
            'posted_at' => date(DATE_ATOM),
            'metrics' => $metrics,
        ]);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$nonce.'.'.$body,
            (string) config('services.telegram.bridge_secret'),
        );
        $request = new Request($url, 'POST');
        $request->setHeader('Content-Type', 'application/json');
        $request->setHeader('X-Telegram-Timestamp', $timestamp);
        $request->setHeader('X-Telegram-Nonce', $nonce);
        $request->setHeader('X-Telegram-Signature', $signature);
        $request->setBody($body);
        $request->setTcpConnectTimeout(5);
        $request->setTlsHandshakeTimeout(5);
        $request->setTransferTimeout(30);
        $request->setInactivityTimeout(15);
        $response = self::bridgeHttpClient()->request($request);
        $responseBody = $response->getBody()->buffer();

        if ($response->getStatus() >= 400) {
            throw new RuntimeException("Telegram bridge returned HTTP {$response->getStatus()}: {$responseBody}");
        }

        if ($responseBody === '') {
            return [];
        }

        $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Bridge calls must use container DNS; MadelineProto's HTTP client intentionally resolves through DoH.
     */
    private static function bridgeHttpClient(): HttpClient
    {
        return self::$bridgeHttpClient ??= HttpClientBuilder::buildDefault();
    }

    private function isAssigned(int $peerId): bool
    {
        if ($this->subscriptionsRefreshedAt === 0) {
            $this->refreshSubscriptions();
        }

        return in_array($peerId, $this->allowedPeerIds, true);
    }

    private function normalizeChannelId(int $peerId): int
    {
        return $peerId > 0 ? -(1_000_000_000_000 + $peerId) : $peerId;
    }

    private function telegramAccountUuid(): string
    {
        return basename($this->getSessionName());
    }
}
