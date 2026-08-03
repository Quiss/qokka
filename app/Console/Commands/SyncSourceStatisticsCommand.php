<?php

namespace App\Console\Commands;

use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Models\Source;
use App\SourceType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:sources:sync-statistics {--source= : ID конкретного источника} {--hours=24 : Глубина синхронизации в часах (1–168)}')]
#[Description('Synchronize posts and engagement statistics for the configured lookback period')]
class SyncSourceStatisticsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = Source::query()
            ->where('type', SourceType::Telegram)
            ->where('is_active', true)
            ->whereNotNull('collector_telegram_account_id')
            ->orderBy('id');
        $sourceId = $this->option('source');
        $hours = (int) $this->option('hours');

        if ($hours < 1 || $hours > 168) {
            $this->error('Параметр --hours должен быть от 1 до 168.');

            return self::FAILURE;
        }

        if (filled($sourceId)) {
            $query->whereKey((int) $sourceId);
        }

        $count = 0;
        $query->each(function (Source $source) use (&$count, $hours): void {
            SyncSourceChannelStatisticsJob::dispatch($source->id, $hours)
                ->onQueue('telegram')
                ->delay(now()->addSeconds($count * 3));
            $count++;
        });

        $this->info("Поставлено в очередь источников: {$count}.");

        return self::SUCCESS;
    }
}
