<?php

namespace App\Services;

use App\Actions\AssignTelegramCollector;
use App\Actions\FindDeletedTelegramChannelParticipants;
use App\Actions\IngestTelegramUpdate;
use App\Actions\RequestTelegramSourceHistorySync;
use App\Contracts\MadelineClient;
use App\Exceptions\TelegramApiServerException;
use App\Models\SourceChannel;
use App\Models\TelegramOwnerCommand;
use App\TelegramOwnerCommandType;
use App\TelegramSourceAccessStatus;
use danog\MadelineProto\RPCErrorException;
use RuntimeException;
use Throwable;

class TelegramOwnerCommandExecutor
{
    public function __construct(
        private readonly TelegramOwnerMediaDownloader $mediaDownloader,
        private readonly TelegramMessagePayloadFactory $payloadFactory,
        private readonly IngestTelegramUpdate $ingestTelegramUpdate,
        private readonly AssignTelegramCollector $assignTelegramCollector,
        private readonly RequestTelegramSourceHistorySync $requestHistorySync,
        private readonly FindDeletedTelegramChannelParticipants $findDeletedParticipants,
    ) {}

    /** @return array<string, mixed> */
    public function execute(
        TelegramOwnerCommand $command,
        MadelineClient $client,
    ): array {
        return match ($command->type) {
            TelegramOwnerCommandType::DownloadMedia => $this->mediaDownloader
                ->handle($command, $client, false),
            TelegramOwnerCommandType::DownloadMediaPreview => $this->mediaDownloader
                ->handle($command, $client, true),
            TelegramOwnerCommandType::VerifySource => $this->verifySource($command, $client),
            TelegramOwnerCommandType::SyncSourceHistory => $this->syncSourceHistory($command, $client),
            TelegramOwnerCommandType::ScanDeletedParticipants => $this->scanDeletedParticipants($command, $client),
            TelegramOwnerCommandType::RemoveDeletedParticipants => $this->removeDeletedParticipants($command, $client),
        };
    }

    public function recordFailure(
        TelegramOwnerCommand $command,
        Throwable $exception,
    ): void {
        if (in_array($command->type, [
            TelegramOwnerCommandType::DownloadMedia,
            TelegramOwnerCommandType::DownloadMediaPreview,
        ], true)) {
            $this->mediaDownloader->recordFailure(
                $command,
                $exception,
                $command->type === TelegramOwnerCommandType::DownloadMediaPreview,
            );
        }

        if ($command->type === TelegramOwnerCommandType::VerifySource) {
            $sourceChannel = SourceChannel::query()->find(
                (int) ($command->payload['source_channel_id'] ?? 0),
            );

            $sourceChannel?->telegramAccounts()->syncWithoutDetaching([
                $command->telegram_account_id => [
                    'access_status' => TelegramSourceAccessStatus::Unavailable->value,
                    'last_checked_at' => now(),
                    'last_error' => $exception->getMessage(),
                ],
            ]);
        }

        if ($command->type === TelegramOwnerCommandType::SyncSourceHistory) {
            $sourceChannel = SourceChannel::query()->find(
                (int) ($command->payload['source_channel_id'] ?? 0),
            );

            if ($sourceChannel !== null) {
                $metadata = is_array($sourceChannel->metadata) ? $sourceChannel->metadata : [];
                $sourceChannel->update([
                    'metadata' => array_merge($metadata, [
                        'statistics_sync' => [
                            'failed_at' => now()->toIso8601String(),
                            'error' => $exception->getMessage(),
                        ],
                    ]),
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function verifySource(
        TelegramOwnerCommand $command,
        MadelineClient $client,
    ): array {
        $sourceChannel = SourceChannel::query()->findOrFail(
            (int) ($command->payload['source_channel_id'] ?? 0),
        );
        $info = $client->getInfo($sourceChannel->telegramReference());

        if (! is_array($info)) {
            throw new RuntimeException('MadelineProto не вернул полную информацию об источнике.');
        }

        $normalized = $this->normalizedPeer($info);
        $peer = $normalized['username'] === null
            ? ($normalized['peer_id'] ?? $sourceChannel->telegramReference())
            : '@'.$normalized['username'];

        if ($normalized['username'] !== null || filled($sourceChannel->username)) {
            try {
                $client->joinChannel($peer);
            } catch (Throwable $exception) {
                if ($this->rpcCode($exception) !== 'USER_ALREADY_PARTICIPANT') {
                    throw $exception;
                }
            }
        }

        $client->muteNotifications($peer);
        $sourceChannel->telegramAccounts()->syncWithoutDetaching([
            $command->telegram_account_id => [
                'access_status' => TelegramSourceAccessStatus::Available->value,
                'last_checked_at' => now(),
                'last_error' => null,
            ],
        ]);
        $sourceChannel->update(array_filter([
            'telegram_peer_id' => $normalized['peer_id'],
            'username' => $normalized['username'],
            'title' => $normalized['title'],
        ], static fn (mixed $value): bool => filled($value)));
        $assignedAccount = $this->assignTelegramCollector->handle($sourceChannel->fresh());

        if ($assignedAccount?->id === $command->telegram_account_id) {
            $this->requestHistorySync->handle($sourceChannel->fresh(), 24);
        }

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
    }

    /** @return array{messages: int, lookback_hours: int} */
    private function syncSourceHistory(
        TelegramOwnerCommand $command,
        MadelineClient $client,
    ): array {
        $sourceChannel = SourceChannel::query()
            ->with('collectorTelegramAccount')
            ->where('is_active', true)
            ->findOrFail((int) ($command->payload['source_channel_id'] ?? 0));
        $telegramAccount = $sourceChannel->collectorTelegramAccount;

        if ($telegramAccount === null || $telegramAccount->id !== $command->telegram_account_id) {
            throw new RuntimeException('Команда синхронизации назначена не текущему Telegram-аккаунту источника.');
        }

        $lookbackHours = max(1, min(168, (int) ($command->payload['lookback_hours'] ?? 24)));
        $cutoffTimestamp = now()->subHours($lookbackHours)->timestamp;
        $offsetId = 0;
        $syncedMessages = 0;

        for ($page = 0; $page < 50; $page++) {
            $history = $client->getHistory(
                $sourceChannel->telegramReference(),
                $offsetId,
                100,
            );
            $messages = is_array($history['messages'] ?? null) ? $history['messages'] : [];
            $oldestTimestamp = null;
            $nextOffsetId = null;

            foreach ($messages as $message) {
                if (! is_array($message) || ($message['_'] ?? null) !== 'message') {
                    continue;
                }

                $messageTimestamp = (int) ($message['date'] ?? 0);
                $messageId = (int) ($message['id'] ?? 0);
                $oldestTimestamp = $oldestTimestamp === null
                    ? $messageTimestamp
                    : min($oldestTimestamp, $messageTimestamp);
                $nextOffsetId = $nextOffsetId === null
                    ? $messageId
                    : min($nextOffsetId, $messageId);

                if ($messageTimestamp < $cutoffTimestamp || $messageId <= 0) {
                    continue;
                }

                $this->ingestTelegramUpdate->handle(
                    $this->payloadFactory->fromRawMessage(
                        $telegramAccount,
                        $sourceChannel,
                        $message,
                    ),
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
                    'lookback_hours' => $lookbackHours,
                ],
            ]),
        ]);

        return ['messages' => $syncedMessages, 'lookback_hours' => $lookbackHours];
    }

    /** @return array{participants: list<array<string, mixed>>} */
    private function scanDeletedParticipants(
        TelegramOwnerCommand $command,
        MadelineClient $client,
    ): array {
        $channel = (string) ($command->payload['channel'] ?? '');

        if (! $client->canBanChannelParticipants($channel)) {
            throw new RuntimeException("Telegram-аккаунт не может удалять участников {$channel}.");
        }

        return ['participants' => $this->findDeletedParticipants->handle($client, $channel)];
    }

    /** @return array{removed: int, failed: int} */
    private function removeDeletedParticipants(
        TelegramOwnerCommand $command,
        MadelineClient $client,
    ): array {
        $channel = (string) ($command->payload['channel'] ?? '');
        $participants = is_array($command->payload['participants'] ?? null)
            ? $command->payload['participants']
            : [];
        $removed = 0;
        $failed = 0;

        foreach ($participants as $participant) {
            $participantId = (int) (is_array($participant) ? ($participant['id'] ?? 0) : 0);

            if ($participantId <= 0) {
                continue;
            }

            try {
                $client->banChannelParticipant($channel, $participantId);
                $client->unbanChannelParticipant($channel, $participantId);
                $removed++;
            } catch (Throwable $exception) {
                if ($this->rpcCode($exception) === 'USER_NOT_PARTICIPANT') {
                    $removed++;

                    continue;
                }

                $failed++;
            }
        }

        return ['removed' => $removed, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{peer_id: int|null, username: string|null, title: string|null}
     */
    private function normalizedPeer(array $info): array
    {
        $chat = is_array($info['Chat'] ?? null) ? $info['Chat'] : [];
        $user = is_array($info['User'] ?? null) ? $info['User'] : [];

        return [
            'peer_id' => isset($info['bot_api_id']) ? (int) $info['bot_api_id'] : null,
            'username' => $chat['username'] ?? $user['username'] ?? null,
            'title' => $chat['title'] ?? (
                trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: null
            ),
        ];
    }

    private function rpcCode(Throwable $exception): ?string
    {
        return match (true) {
            $exception instanceof RPCErrorException => $exception->rpc,
            $exception instanceof TelegramApiServerException => $exception->rpc,
            default => null,
        };
    }
}
