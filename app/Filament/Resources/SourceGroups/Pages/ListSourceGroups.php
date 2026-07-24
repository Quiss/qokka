<?php

namespace App\Filament\Resources\SourceGroups\Pages;

use App\Filament\Resources\SourceGroups\SourceGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSourceGroups extends ListRecords
{
    protected static string $resource = SourceGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
