<?php

namespace App\Services;

use App\Jobs\IngestTelegramUpdateJob;
use App\Models\Source;
use App\Models\TelegramAccount;
use App\SourceType;
use App\TelegramAccountStatus;

class TelegramApiServerUpdateHandler
{
    public function __construct(
        private readonly TelegramMessagePayloadFactory $payloadFactory,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $session = data_get($event, 'result.session');
        $update = data_get($event, 'result.update');

        if (! is_string($session) || ! is_array($update)) {
            return;
        }

        $telegramAccount = TelegramAccount::query()
            ->where('uuid', $session)
            ->where('is_active', true)
            ->first();

        if ($telegramAccount === null) {
            return;
        }

        $telegramAccount->update([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
            'last_error' => null,
        ]);

        switch ($update['_'] ?? null) {
            case 'updateNewChannelMessage':
                $this->message($telegramAccount, $update, 'message');
                break;
            case 'updateEditChannelMessage':
                $this->message($telegramAccount, $update, 'edit');
                break;
            case 'updateDeleteChannelMessages':
                $this->deletedMessages($telegramAccount, $update);
                break;
            case 'updateChannelMessageViews':
                $this->metrics($telegramAccount, $update, 'views');
                break;
            case 'updateChannelMessageForwards':
                $this->metrics($telegramAccount, $update, 'forwards');
                break;
        }
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function message(
        TelegramAccount $telegramAccount,
        array $update,
        string $eventType,
    ): void {
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];

        if (($message['_'] ?? null) !== 'message' || (bool) ($message['out'] ?? false)) {
            return;
        }

        $peerId = $this->messagePeerId($message);
        $source = $this->source($telegramAccount, $peerId);

        if ($source === null) {
            return;
        }

        $payload = $this->payloadFactory->fromRawMessage(
            $telegramAccount,
            $source,
            $message,
        );
        $payload['event_type'] = $eventType;

        IngestTelegramUpdateJob::dispatch($payload)->onQueue('ingest');
    }

    /** @param array<string, mixed> $update */
    private function deletedMessages(TelegramAccount $telegramAccount, array $update): void
    {
        $peerId = $this->channelPeerId($update['channel_id'] ?? null);

        if ($this->source($telegramAccount, $peerId) === null) {
            return;
        }

        $messageIds = is_array($update['messages'] ?? null) ? $update['messages'] : [];

        foreach ($messageIds as $messageId) {
            if (! is_numeric($messageId)) {
                continue;
            }

            IngestTelegramUpdateJob::dispatch([
                'telegram_account_uuid' => $telegramAccount->uuid,
                'event_type' => 'delete',
                'peer_id' => $peerId,
                'message_id' => (int) $messageId,
                'posted_at' => now()->toIso8601String(),
            ])->onQueue('ingest');
        }
    }

    /** @param array<string, mixed> $update */
    private function metrics(
        TelegramAccount $telegramAccount,
        array $update,
        string $metric,
    ): void {
        $peerId = $this->channelPeerId($update['channel_id'] ?? null);

        if (
            $this->source($telegramAccount, $peerId) === null
            || ! is_numeric($update['id'] ?? null)
            || ! is_numeric($update[$metric] ?? null)
        ) {
            return;
        }

        IngestTelegramUpdateJob::dispatch([
            'telegram_account_uuid' => $telegramAccount->uuid,
            'event_type' => 'metrics',
            'peer_id' => $peerId,
            'message_id' => (int) $update['id'],
            'posted_at' => now()->toIso8601String(),
            'metrics' => [$metric => max(0, (int) $update[$metric])],
        ])->onQueue('ingest');
    }

    private function source(
        TelegramAccount $telegramAccount,
        ?int $peerId,
    ): ?Source {
        if ($peerId === null) {
            return null;
        }

        return Source::query()
            ->where('type', SourceType::Telegram)
            ->where('is_active', true)
            ->whereBelongsTo($telegramAccount, 'collectorTelegramAccount')
            ->where('telegram_peer_id', $peerId)
            ->first();
    }

    /** @param array<string, mixed> $message */
    private function messagePeerId(array $message): ?int
    {
        $peer = is_array($message['peer_id'] ?? null) ? $message['peer_id'] : [];

        return match ($peer['_'] ?? null) {
            'peerChannel' => $this->channelPeerId($peer['channel_id'] ?? null),
            'peerChat' => is_numeric($peer['chat_id'] ?? null) ? -(int) $peer['chat_id'] : null,
            'peerUser' => is_numeric($peer['user_id'] ?? null) ? (int) $peer['user_id'] : null,
            default => null,
        };
    }

    private function channelPeerId(mixed $channelId): ?int
    {
        if (! is_numeric($channelId)) {
            return null;
        }

        return -(1_000_000_000_000 + (int) $channelId);
    }
}
