<?php

namespace App\Filament\Resources\SourceGroups;

use App\Filament\Resources\SourceGroups\Pages\CreateSourceGroup;
use App\Filament\Resources\SourceGroups\Pages\EditSourceGroup;
use App\Filament\Resources\SourceGroups\Pages\ListSourceGroups;
use App\Filament\Resources\SourceGroups\Schemas\SourceGroupForm;
use App\Filament\Resources\SourceGroups\Tables\SourceGroupsTable;
use App\Models\SourceGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SourceGroupResource extends Resource
{
    protected static ?string $model = SourceGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $modelLabel = 'группа источников';

    protected static ?string $pluralModelLabel = 'Группы источников';

    protected static string|\UnitEnum|null $navigationGroup = 'Настройка';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return SourceGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SourceGroupsTable::configure($table);
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
            'index' => ListSourceGroups::route('/'),
            'create' => CreateSourceGroup::route('/create'),
            'edit' => EditSourceGroup::route('/{record}/edit'),
        ];
    }
}
