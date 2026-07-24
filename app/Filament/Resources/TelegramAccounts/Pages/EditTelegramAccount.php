<?php

namespace App\Filament\Resources\TelegramAccounts\Pages;

use App\Filament\Resources\TelegramAccounts\TelegramAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramAccount extends EditRecord
{
    protected static string $resource = TelegramAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
