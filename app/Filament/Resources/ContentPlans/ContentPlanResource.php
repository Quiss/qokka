<?php

namespace App\Filament\Resources\ContentPlans;

use App\Filament\Resources\ContentPlans\Pages\EditContentPlan;
use App\Filament\Resources\ContentPlans\Pages\ListContentPlans;
use App\Filament\Resources\ContentPlans\RelationManagers\PlannedPostsRelationManager;
use App\Filament\Resources\ContentPlans\RelationManagers\StoryCandidatesRelationManager;
use App\Filament\Resources\ContentPlans\Schemas\ContentPlanForm;
use App\Filament\Resources\ContentPlans\Tables\ContentPlansTable;
use App\Models\ContentPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ContentPlanResource extends Resource
{
    protected static ?string $model = ContentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $modelLabel = 'редакционный план';

    protected static ?string $pluralModelLabel = 'Редакция';

    protected static string|\UnitEnum|null $navigationGroup = 'Работа';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return ContentPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StoryCandidatesRelationManager::class,
            PlannedPostsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContentPlans::route('/'),
            'edit' => EditContentPlan::route('/{record}/edit'),
        ];
    }
}
