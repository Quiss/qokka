<?php

namespace App\Jobs;

use App\Actions\IngestTelegramUpdate;
use App\Models\SourceChannel;
use App\Services\MadelineClientPool;
use App\Services\TelegramMessagePayloadFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSourceChannelStatisticsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $sourceChannelId,
        public readonly int $lookbackHours = 24,
    ) {}

    public function uniqueId(): string
    {
        return $this->sourceChannelId.':'.$this->lookbackHours;
    }

    /**
     * Synchronize up to 5,000 recent messages, stopping at the configured boundary.
     */
    public function handle(
        MadelineClientPool $clientPool,
        TelegramMessagePayloadFactory $payloadFactory,
        IngestTelegramUpdate $ingestTelegramUpdate,
    ): void {
        $sourceChannel = SourceChannel::query()
            ->with('collectorTelegramAccount')
            ->where('is_active', true)
            ->find($this->sourceChannelId);
        $telegramAccount = $sourceChannel?->collectorTelegramAccount;

        if ($sourceChannel === null || $telegramAccount === null || ! $telegramAccount->is_active) {
            return;
        }

        $client = $clientPool->forAccount($telegramAccount);
        $cutoffTimestamp = now()->subHours($this->lookbackHours)->timestamp;
        $offsetId = 0;
        $syncedMessages = 0;

        for ($page = 0; $page < 50; $page++) {
            try {
                $history = $client->getHistory(
                    $sourceChannel->telegramReference(),
                    $offsetId,
                    100,
                );
            } catch (Throwable $exception) {
                $clientPool->forget($telegramAccount);

                throw $exception;
            }
            $messages = $history['messages'] ?? [];
            $oldestTimestamp = null;
            $nextOffsetId = null;

            foreach ($messages as $message) {
                if (($message['_'] ?? null) !== 'message') {
                    continue;
                }

                $messageTimestamp = (int) ($message['date'] ?? 0);
                $messageId = (int) ($message['id'] ?? 0);
                $oldestTimestamp = $oldestTimestamp === null
                    ? $messageTimestamp
                    : min($oldestTimestamp, $messageTimestamp);
                $nextOffsetId = $nextOffsetId === null ? $messageId : min($nextOffsetId, $messageId);

                if ($messageTimestamp < $cutoffTimestamp || $messageId <= 0) {
                    continue;
                }

                $ingestTelegramUpdate->handle(
                    $payloadFactory->fromRawMessage($telegramAccount, $sourceChannel, $message),
                );
                $syncedMessages++;
            }

            if (
                count($messages) < 100
                || $oldestTimestamp === null
                || $oldestTimestamp <= $cutoffTimestamp
                || $nextOffsetId === null
            ) {
                break;
            }

            $offsetId = $nextOffsetId;
        }

        $metadata = is_array($sourceChannel->metadata) ? $sourceChannel->metadata : [];
        $sourceChannel->update([
            'last_backfilled_at' => now(),
            'metadata' => array_merge($metadata, [
                'statistics_sync' => [
                    'synced_at' => now()->toIso8601String(),
                    'messages' => $syncedMessages,
                    'lookback_hours' => $this->lookbackHours,
                ],
            ]),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $sourceChannel = SourceChannel::query()->find($this->sourceChannelId);

        if ($sourceChannel !== null) {
            $metadata = is_array($sourceChannel->metadata) ? $sourceChannel->metadata : [];
            $sourceChannel->update([
                'metadata' => array_merge($metadata, [
                    'statistics_sync' => [
                        'failed_at' => now()->toIso8601String(),
                        'error' => $exception?->getMessage(),
                    ],
                ]),
            ]);
        }

        Log::error('Telegram source statistics synchronization failed.', [
            'source_channel_id' => $this->sourceChannelId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
