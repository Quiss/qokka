<?php

namespace App\Filament\Resources\StoryCandidates\Pages;

use App\Filament\Resources\StoryCandidates\StoryCandidateResource;
use Filament\Resources\Pages\ListRecords;

class ListStoryCandidates extends ListRecords
{
    protected static string $resource = StoryCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
