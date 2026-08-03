<?php

namespace App\Console\Commands;

use App\Jobs\SyncJsonCollectionSourceJob;
use App\Models\Source;
use App\SourceType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sources:sync-json {--source= : ID конкретного JSON-источника}')]
#[Description('Queue synchronization for active JSON collection sources')]
class SyncJsonCollectionSourcesCommand extends Command
{
    public function handle(): int
    {
        $query = Source::query()
            ->where('type', SourceType::JsonCollection)
            ->where('is_active', true)
            ->orderBy('id');

        if ($this->option('source') !== null) {
            $query->whereKey((int) $this->option('source'));
        }

        $count = 0;
        $query->each(function (Source $source) use (&$count): void {
            SyncJsonCollectionSourceJob::dispatch($source->id)->onQueue('ingest');
            $count++;
        });

        $this->info("Поставлено в очередь JSON-источников: {$count}.");

        return self::SUCCESS;
    }
}
