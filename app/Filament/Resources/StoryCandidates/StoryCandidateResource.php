<?php

namespace App\Filament\Resources\StoryCandidates;

use App\Filament\Resources\StoryCandidates\Pages\EditStoryCandidate;
use App\Filament\Resources\StoryCandidates\Pages\ListStoryCandidates;
use App\Filament\Resources\StoryCandidates\Schemas\StoryCandidateForm;
use App\Filament\Resources\StoryCandidates\Tables\StoryCandidatesTable;
use App\Models\StoryCandidate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StoryCandidateResource extends Resource
{
    protected static ?string $model = StoryCandidate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return StoryCandidateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StoryCandidatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoryCandidates::route('/'),
            'edit' => EditStoryCandidate::route('/{record}/edit'),
        ];
    }
}
