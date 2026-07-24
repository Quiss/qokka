<?php

namespace App\Filament\Resources\PlannedPosts\Pages;

use App\Filament\Resources\PlannedPosts\PlannedPostResource;
use Filament\Resources\Pages\ListRecords;

class ListPlannedPosts extends ListRecords
{
    protected static string $resource = PlannedPostResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
