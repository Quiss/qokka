<?php

namespace App\Filament\Resources\ContentPlans\RelationManagers;

use App\Filament\Resources\StoryCandidates\Schemas\StoryCandidateForm;
use App\Filament\Resources\StoryCandidates\Tables\StoryCandidatesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class StoryCandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'storyCandidates';

    protected static ?string $title = '1. Отбор новостей';

    public function form(Schema $schema): Schema
    {
        return StoryCandidateForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return StoryCandidatesTable::configure($table);
    }
}
