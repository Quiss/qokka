<?php

namespace App\Filament\Resources\ContentPlans\Pages;

use App\Filament\Resources\ContentPlans\ContentPlanResource;
use Filament\Resources\Pages\ListRecords;

class ListContentPlans extends ListRecords
{
    protected static string $resource = ContentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
