<?php

namespace App\Filament\Resources\Publications\Pages;

use App\Filament\Resources\Publications\Actions\PublicationNotesAction;
use App\Filament\Resources\Publications\PublicationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPublication extends EditRecord
{
    protected static string $resource = PublicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PublicationNotesAction::make(),
            DeleteAction::make(),
        ];
    }
}
