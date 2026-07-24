<?php

namespace App\Filament\Resources\SourceChannels\Pages;

use App\Filament\Resources\SourceChannels\SourceChannelResource;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;
use Filament\Resources\Pages\CreateRecord;
use LogicException;

class CreateSourceChannel extends CreateRecord
{
    protected static string $resource = SourceChannelResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof SourceChannel) {
            throw new LogicException('Source channel create page requires a source channel record.');
        }

        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
    }
}
