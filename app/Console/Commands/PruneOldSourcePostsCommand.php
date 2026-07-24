<?php

namespace App\Console\Commands;

use App\Actions\PruneOldSourcePosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('source-posts:prune {--days=20 : Количество полных дней хранения исходных постов и медиа}')]
#[Description('Delete source posts and media older than the configured retention period')]
class PruneOldSourcePostsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PruneOldSourcePosts $pruneOldSourcePosts): int
    {
        $retentionDays = (int) $this->option('days');

        if ($retentionDays < 1) {
            $this->error('Параметр --days должен быть не меньше 1.');

            return self::FAILURE;
        }

        $result = $pruneOldSourcePosts->handle($retentionDays);

        $this->info(
            "Удалено старше {$retentionDays} дн.: постов {$result['posts']}, "
            ."медиа {$result['media']}, файлов {$result['files']}.",
        );

        return self::SUCCESS;
    }
}
