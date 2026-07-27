<?php

namespace App\Console\Commands;

use App\Actions\PruneOldPlannedPostMedia;
use App\Actions\PruneOldSourcePosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('content-storage:prune {--days= : Количество полных дней хранения исходников и медиа}')]
#[Description('Delete expired source content and terminal planned post media')]
class PruneContentStorageCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        PruneOldSourcePosts $pruneOldSourcePosts,
        PruneOldPlannedPostMedia $pruneOldPlannedPostMedia,
    ): int {
        $retentionDays = $this->option('days') === null
            ? (int) config('channelbot.content.retention_days', 14)
            : (int) $this->option('days');

        if ($retentionDays < 1) {
            $this->error('Параметр --days должен быть не меньше 1.');

            return self::FAILURE;
        }

        $sourceResult = $pruneOldSourcePosts->handle($retentionDays);
        $plannedResult = $pruneOldPlannedPostMedia->handle($retentionDays);

        $this->info(
            "Удалено старше {$retentionDays} дн.: исходных постов {$sourceResult['posts']}, "
            ."медиа источников {$sourceResult['media']}, "
            ."медиа подготовленных постов {$plannedResult['media']}, "
            .'файлов '.($sourceResult['files'] + $plannedResult['files']).'.',
        );

        return self::SUCCESS;
    }
}
