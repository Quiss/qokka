<?php

namespace App\Filament\Resources\ContentPlans\RelationManagers;

use App\Filament\Resources\PlannedPosts\Schemas\PlannedPostForm;
use App\Filament\Resources\PlannedPosts\Tables\PlannedPostsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class PlannedPostsRelationManager extends RelationManager
{
    protected static string $relationship = 'plannedPosts';

    protected static ?string $title = '2. Рерайт и публикация';

    public function form(Schema $schema): Schema
    {
        return PlannedPostForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return PlannedPostsTable::configure($table);
    }
}
