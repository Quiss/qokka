<?php

namespace App\Filament\Resources\PlannedPosts\Pages;

use App\Filament\Resources\PlannedPosts\PlannedPostResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlannedPost extends CreateRecord
{
    protected static string $resource = PlannedPostResource::class;
}
