<?php

namespace App\Filament\Resources\TelegramAccounts\Pages;

use App\Filament\Resources\TelegramAccounts\TelegramAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListTelegramAccounts extends ListRecords
{
    protected static string $resource = TelegramAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
