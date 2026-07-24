<?php

namespace App\Filament\Resources\TelegramAccounts\Pages;

use App\Filament\Resources\TelegramAccounts\TelegramAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramAccount extends CreateRecord
{
    protected static string $resource = TelegramAccountResource::class;
}
