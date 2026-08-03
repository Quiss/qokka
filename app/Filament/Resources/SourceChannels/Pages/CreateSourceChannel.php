<?php

namespace App\Filament\Resources\SourceChannels\Pages;

use App\Filament\Resources\SourceChannels\SourceChannelResource;
use App\Jobs\SyncJsonCollectionSourceJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\Source;
use App\SourceType;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

class CreateSourceChannel extends CreateRecord
{
    protected static string $resource = SourceChannelResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return parent::handleRecordCreation($data);
        } catch (UniqueConstraintViolationException $exception) {
            if (
                $exception->index === 'source_channels_username_unique'
                || in_array('username', $exception->columns, true)
            ) {
                throw ValidationException::withMessages([
                    'data.username' => 'Этот Telegram-канал уже добавлен в источники.',
                ]);
            }

            if (
                $exception->index === 'source_channels_telegram_peer_id_unique'
                || in_array('telegram_peer_id', $exception->columns, true)
            ) {
                throw ValidationException::withMessages([
                    'data.telegram_peer_id' => 'Источник с таким Telegram peer ID уже добавлен.',
                ]);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['type'] ?? null) === SourceType::JsonCollection->value) {
            $data['telegram_peer_id'] = null;
            $data['username'] = null;
            $data['preferred_collector_telegram_account_id'] = null;
            $data['collector_telegram_account_id'] = null;

            if (blank(data_get($data, 'credentials.authorization'))) {
                $data['credentials'] = null;
            }

            return $data;
        }

        $data['endpoint_url'] = null;
        $data['settings'] = [];
        $data['credentials'] = null;

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Source) {
            throw new LogicException('Source channel create page requires a source channel record.');
        }

        if ($record->type === SourceType::JsonCollection) {
            SyncJsonCollectionSourceJob::dispatch($record->id)->onQueue('ingest');

            return;
        }

        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
    }
}
