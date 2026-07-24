<?php

declare(strict_types=1);

namespace App\Telegram;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ChannelSourceEventHandler extends SimpleEventHandler
{
    /** @var list<int> */
    private array $allowedPeerIds = [];

    private int $subscriptionsRefreshedAt = 0;

    private static ?HttpClient $bridgeHttpClient = null;

    public function onStart(): void
    {
        $this->refreshSubscriptions();
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
        $relativePath = 'telegram/'.date('Y/m', $message->date).'/'.Str::uuid().'.'.$extension;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $externalId = $this->mediaExternalId($media);
        $metadata = array_filter([
            'bot_api_file_id' => $media->botApiFileId,
            'telegram_media_id' => $media->location['id'] ?? null,
            'file_name' => $media->fileName,
            'width' => property_exists($media, 'width') ? $media->width : null,
            'height' => property_exists($media, 'height') ? $media->height : null,
            'duration' => property_exists($media, 'duration') ? $media->duration : null,
        ], static fn (mixed $value): bool => $value !== null);

        if (in_array($type, ['video', 'animation'], true)) {
            return [[
                'type' => $type,
                'external_id' => $externalId,
                'mime_type' => $media->mimeType,
                'size_bytes' => $media->size,
                'metadata' => $metadata,
            ]];
        }

        try {
            File::ensureDirectoryExists(dirname($absolutePath));
            $media->downloadToFile($absolutePath);

            return [[
                'type' => $type,
                'external_id' => $externalId,
                'disk' => 'local',
                'path' => $relativePath,
                'mime_type' => $media->mimeType,
                'size_bytes' => $media->size,
                'checksum' => hash_file('sha256', $absolutePath),
                'metadata' => $metadata,
            ]];
        } catch (Throwable $exception) {
            return [[
                'type' => $type,
                'external_id' => $externalId,
                'mime_type' => $media->mimeType,
                'size_bytes' => $media->size,
                'metadata' => array_merge($metadata, ['download_error' => $exception->getMessage()]),
            ]];
        }
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
