<?php

namespace App\Jobs;

use App\Models\Source;
use App\Services\JsonCollectionSourceSynchronizer;
use App\SourceType;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SyncJsonCollectionSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $sourceId) {}

    public function uniqueId(): string
    {
        return (string) $this->sourceId;
    }

    public function handle(JsonCollectionSourceSynchronizer $synchronizer): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null || ! $source->is_active || $source->type !== SourceType::JsonCollection) {
            return;
        }

        try {
            $synchronizer->handle($source);
        } catch (Throwable $exception) {
            $source->update([
                'last_sync_error' => Str::limit($exception->getMessage(), 2000),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Source::query()->whereKey($this->sourceId)->update([
            'last_sync_error' => Str::limit(
                $exception?->getMessage() ?? 'Неизвестная ошибка синхронизации.',
                2000,
            ),
        ]);
    }
}
