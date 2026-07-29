<?php

namespace App\Actions;

use App\Jobs\DownloadMediaAssetJob;
use App\MediaType;
use App\Models\SourceChannel;
use App\Models\SourceMessage;
use App\Models\SourcePost;
use App\Models\TelegramAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IngestTelegramUpdate
{
    /** @param array<string, mixed> $payload */
    public function handle(array $payload): ?SourcePost
    {
        $account = TelegramAccount::query()
            ->where('uuid', $payload['telegram_account_uuid'])
            ->first();

        if ($account === null) {
            return null;
        }

        $channel = SourceChannel::query()
            ->where('is_active', true)
            ->whereBelongsTo($account, 'collectorTelegramAccount')
            ->where(function ($query) use ($payload): void {
                $query->where('telegram_peer_id', $payload['peer_id']);

                if (filled($payload['username'] ?? null)) {
                    $query->orWhere('username', ltrim((string) $payload['username'], '@'));
                }
            })
            ->first();

        if ($channel === null) {
            return null;
        }

        return DB::transaction(function () use ($account, $channel, $payload): ?SourcePost {
            SourceChannel::query()
                ->whereKey($channel->id)
                ->lockForUpdate()
                ->firstOrFail();

            $eventType = (string) $payload['event_type'];
            $isAlbum = filled($payload['grouped_id'] ?? null);
            $canonicalKey = $isAlbum ? 'album:'.$payload['grouped_id'] : 'message:'.$payload['message_id'];
            $postedAt = CarbonImmutable::parse($payload['posted_at'] ?? now());
            $isDeleted = $eventType === 'delete';
            $isMetricsUpdate = $eventType === 'metrics';
            $existingMessage = ($isDeleted || $isMetricsUpdate) ? SourceMessage::query()
                ->where('source_channel_id', $channel->id)
                ->where('external_message_id', $payload['message_id'])
                ->first() : null;

            if ($isMetricsUpdate && $existingMessage === null) {
                return null;
            }

            if ($existingMessage !== null) {
                $sourcePost = $existingMessage->sourcePost;
            } else {
                $sourcePost = SourcePost::query()->firstOrCreate(
                    ['source_channel_id' => $channel->id, 'canonical_key' => $canonicalKey],
                    [
                        'telegram_grouped_id' => $payload['grouped_id'] ?? null,
                        'posted_at' => $postedAt,
                        'source_url' => filled($channel->username) ? 'https://t.me/'.$channel->username.'/'.$payload['message_id'] : null,
                    ],
                );
            }

            $incomingMetrics = is_array($payload['metrics'] ?? null)
                ? array_filter($payload['metrics'], static fn (mixed $value): bool => $value !== null)
                : [];
            $existingMetrics = $existingMessage !== null && is_array($existingMessage->metrics)
                ? $existingMessage->metrics
                : [];
            $metrics = array_merge($existingMetrics, $incomingMetrics);
            $messageValues = [
                'metrics' => $metrics,
                'views' => max(0, (int) ($metrics['views'] ?? 0)),
                'forwards' => max(0, (int) ($metrics['forwards'] ?? 0)),
                'reactions' => max(0, (int) ($metrics['reactions'] ?? 0)),
                'comments' => max(0, (int) ($metrics['comments'] ?? 0)),
            ];

            if (! $isMetricsUpdate) {
                $messageValues = array_merge($messageValues, [
                    'telegram_account_id' => $account->id,
                    'telegram_grouped_id' => $payload['grouped_id'] ?? null,
                    'text' => $payload['text'] ?? null,
                    'entities' => $payload['entities'] ?? [],
                    'raw_payload' => $payload['raw'] ?? $payload,
                    'posted_at' => $postedAt,
                    'edited_at' => $eventType === 'edit' ? now() : null,
                    'deleted_at' => $isDeleted ? now() : null,
                ]);
            }

            $message = $sourcePost->messages()->updateOrCreate(
                ['source_channel_id' => $channel->id, 'external_message_id' => $payload['message_id']],
                $messageValues,
            );

            $mediaJobs = [];

            foreach ($isMetricsUpdate ? [] : ($payload['media'] ?? []) as $index => $media) {
                $type = MediaType::tryFrom($media['type'] ?? '') ?? MediaType::Document;
                $ingestKey = $message->id.':'.$index;
                $externalId = (string) ($media['external_id'] ?? $ingestKey);
                $asset = $sourcePost->mediaAssets()->firstOrNew(['ingest_key' => $ingestKey]);
                $isSameMedia = $asset->exists && $asset->external_id === $externalId;
                $incomingPath = $media['path'] ?? null;
                $metadata = is_array($media['metadata'] ?? null) ? $media['metadata'] : [];

                $asset->fill([
                    'source_message_id' => $message->id,
                    'external_id' => $externalId,
                    'type' => $type,
                    'disk' => $isSameMedia && blank($incomingPath)
                        ? $asset->disk
                        : ($media['disk'] ?? 'local'),
                    'path' => $isSameMedia && blank($incomingPath)
                        ? $asset->path
                        : $incomingPath,
                    'preview_disk' => $isSameMedia ? $asset->preview_disk : null,
                    'preview_path' => $isSameMedia ? $asset->preview_path : null,
                    'preview_mime_type' => $isSameMedia ? $asset->preview_mime_type : null,
                    'mime_type' => $media['mime_type'] ?? null,
                    'size_bytes' => $media['size_bytes'] ?? null,
                    'checksum' => $isSameMedia && blank($incomingPath)
                        ? $asset->checksum
                        : ($media['checksum'] ?? null),
                    'sort_order' => $index,
                    'metadata' => $isSameMedia
                        ? array_merge(is_array($asset->metadata) ? $asset->metadata : [], $metadata)
                        : $metadata,
                    'downloaded_at' => $isSameMedia && blank($incomingPath)
                        ? $asset->downloaded_at
                        : (filled($incomingPath) ? now() : null),
                    'preview_downloaded_at' => $isSameMedia ? $asset->preview_downloaded_at : null,
                    'failed_at' => $isSameMedia ? $asset->failed_at : null,
                    'preview_failed_at' => $isSameMedia ? $asset->preview_failed_at : null,
                ]);
                $asset->save();

                if (
                    $asset->path === null
                    && in_array($type, [MediaType::Photo, MediaType::Animation, MediaType::Document], true)
                ) {
                    $mediaJobs[] = [$asset->id, false];
                } elseif ($asset->preview_path === null && $type === MediaType::Video && filled(data_get($asset->metadata, 'thumbnail_type'))) {
                    $mediaJobs[] = [$asset->id, true];
                }
            }

            $activeMessages = $sourcePost->messages()->whereNull('deleted_at')->orderBy('external_message_id')->get();
            $text = $activeMessages->pluck('text')->filter()->implode("\n\n");
            $representativeMessage = $activeMessages
                ->sortByDesc('reactions')
                ->first();
            $representativeMetrics = $representativeMessage !== null && is_array($representativeMessage->metrics)
                ? $representativeMessage->metrics
                : [];
            $sourcePostMetrics = is_array($sourcePost->metrics) ? $sourcePost->metrics : [];
            $sourcePostMetadata = is_array($sourcePost->metadata) ? $sourcePost->metadata : [];
            $views = (int) ($activeMessages->max('views') ?? 0);
            $forwards = (int) ($activeMessages->max('forwards') ?? 0);
            $reactions = (int) ($activeMessages->max('reactions') ?? 0);
            $comments = (int) ($activeMessages->max('comments') ?? 0);
            $sourcePost->update([
                'text' => $text ?: null,
                'normalized_text' => $text ? Str::lower(Str::squish($text)) : null,
                'metrics' => array_merge($sourcePostMetrics, $representativeMetrics, [
                    'views' => $views,
                    'forwards' => $forwards,
                    'reactions' => $reactions,
                    'comments' => $comments,
                ]),
                'views' => $views,
                'forwards' => $forwards,
                'reactions' => $reactions,
                'comments' => $comments,
                'metadata' => array_merge($sourcePostMetadata, ['last_event_type' => $eventType]),
                'status' => $activeMessages->isEmpty() ? 'deleted' : 'active',
                'edited_at' => $eventType === 'edit' ? now() : $sourcePost->edited_at,
                'deleted_at' => $activeMessages->isEmpty() ? now() : null,
            ]);
            $channel->update(['last_event_at' => now()]);

            DB::afterCommit(function () use ($mediaJobs): void {
                foreach ($mediaJobs as [$assetId, $previewOnly]) {
                    DownloadMediaAssetJob::dispatch($assetId, $previewOnly)->onQueue(
                        $previewOnly
                            ? DownloadMediaAssetJob::BACKGROUND_QUEUE
                            : DownloadMediaAssetJob::HIGH_PRIORITY_QUEUE,
                    );
                }
            });

            return $sourcePost->fresh(['messages', 'mediaAssets']);
        });
    }
}
