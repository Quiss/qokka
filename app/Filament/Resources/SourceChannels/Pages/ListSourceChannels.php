<?php

namespace App\Filament\Resources\SourceChannels\Pages;

use App\Filament\Resources\SourceChannels\SourceChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSourceChannels extends ListRecords
{
    protected static string $resource = SourceChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
