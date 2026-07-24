<?php

namespace App\Console\Commands;

use App\Actions\PurgeSourceChannelContent;
use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Models\SourceChannel;
use App\Models\SourcePost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('telegram:sources:resync
    {--hours=48 : Глубина повторной синхронизации в часах (1–168)}
    {--source= : ID конкретного источника; без параметра обрабатываются все источники}
    {--force : Не запрашивать подтверждение}')]
#[Description('Delete imported source posts and media, then queue a fresh Telegram history synchronization')]
class ResyncTelegramSourcesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PurgeSourceChannelContent $purgeSourceChannelContent): int
    {
        $hours = (int) $this->option('hours');

        if ($hours < 1 || $hours > 168) {
            $this->error('Параметр --hours должен быть от 1 до 168.');

            return self::FAILURE;
        }

        $query = SourceChannel::query()->orderBy('id');
        $sourceId = $this->option('source');

        if (filled($sourceId)) {
            $query->whereKey((int) $sourceId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->error('Источники для пересинхронизации не найдены.');

            return self::FAILURE;
        }

        $postIds = SourcePost::query()
            ->whereIn('source_channel_id', $sources->modelKeys())
            ->select('id');
        $postCount = (clone $postIds)->count();
        $candidateLinkCount = DB::table('source_post_story_candidate')
            ->whereIn('source_post_id', $postIds)
            ->count();

        $this->warn("Будут удалены {$postCount} исходных постов и их медиа.");

        if ($candidateLinkCount > 0) {
            $this->warn("Будут удалены {$candidateLinkCount} связей с кандидатами. Сами контент-планы и кандидаты сохранятся.");
        }

        if (! $this->option('force') && ! $this->confirm("Продолжить и затем загрузить историю за {$hours} ч.?")) {
            $this->info('Пересинхронизация отменена.');

            return self::SUCCESS;
        }

        $totals = ['posts' => 0, 'messages' => 0, 'media' => 0, 'candidate_links' => 0, 'files' => 0];
        $queued = 0;

        foreach ($sources as $source) {
            $result = $purgeSourceChannelContent->handle($source);

            foreach (array_keys($totals) as $key) {
                $totals[$key] += $result[$key];
            }

            if ($source->is_active && $source->collector_telegram_account_id !== null) {
                SyncSourceChannelStatisticsJob::dispatch($source->id, $hours)
                    ->onQueue('telegram')
                    ->delay(now()->addSeconds($queued * 3));
                $queued++;
            } else {
                $this->warn("Источник #{$source->id} «{$source->title}» очищен, но не поставлен в очередь: он неактивен или не назначен Telegram-аккаунт.");
            }
        }

        $this->info(
            "Удалено: постов {$totals['posts']}, сообщений {$totals['messages']}, "
            ."медиа {$totals['media']}, файлов {$totals['files']}, "
            ."связей с кандидатами {$totals['candidate_links']}.",
        );
        $this->info("Поставлено в очередь источников: {$queued}. Период: {$hours} ч.");

        return self::SUCCESS;
    }
}
