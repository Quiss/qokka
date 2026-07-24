<?php

namespace App\Filament\Resources\SourceChannels\Pages;

use App\Filament\Resources\SourceChannels\SourceChannelResource;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use LogicException;

class EditSourceChannel extends EditRecord
{
    protected static string $resource = SourceChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof SourceChannel) {
            throw new LogicException('Source channel edit page requires a source channel record.');
        }

        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
    }
}
