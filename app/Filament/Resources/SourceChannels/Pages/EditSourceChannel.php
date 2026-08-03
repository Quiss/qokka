<?php

namespace App\Filament\Resources\SourceChannels\Pages;

use App\Filament\Resources\SourceChannels\SourceChannelResource;
use App\Jobs\SyncJsonCollectionSourceJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\Source;
use App\SourceType;
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

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Source || $record->type !== SourceType::JsonCollection) {
            $data['endpoint_url'] = null;
            $data['settings'] = [];
            $data['credentials'] = null;

            return $data;
        }

        $data['telegram_peer_id'] = null;
        $data['username'] = null;
        $data['preferred_collector_telegram_account_id'] = null;
        $data['collector_telegram_account_id'] = null;

        if (blank(data_get($data, 'credentials.authorization'))) {
            unset($data['credentials']);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Source) {
            throw new LogicException('Source channel edit page requires a source channel record.');
        }

        if ($record->type === SourceType::JsonCollection) {
            SyncJsonCollectionSourceJob::dispatch($record->id)->onQueue('ingest');

            return;
        }

        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
    }
}
