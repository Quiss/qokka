<?php

namespace App\Filament\Resources\StoryCandidates\Pages;

use App\Filament\Resources\StoryCandidates\StoryCandidateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStoryCandidate extends EditRecord
{
    protected static string $resource = StoryCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
