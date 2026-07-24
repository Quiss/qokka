<?php

namespace App\Filament\Resources\SourceGroups\Pages;

use App\Filament\Resources\SourceGroups\SourceGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSourceGroup extends EditRecord
{
    protected static string $resource = SourceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
