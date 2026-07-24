<?php

namespace App\Filament\Resources\PlannedPosts;

use App\Filament\Resources\PlannedPosts\Pages\EditPlannedPost;
use App\Filament\Resources\PlannedPosts\Pages\ListPlannedPosts;
use App\Filament\Resources\PlannedPosts\Schemas\PlannedPostForm;
use App\Filament\Resources\PlannedPosts\Tables\PlannedPostsTable;
use App\Models\PlannedPost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlannedPostResource extends Resource
{
    protected static ?string $model = PlannedPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return PlannedPostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlannedPostsTable::configure($table);
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
            'index' => ListPlannedPosts::route('/'),
            'edit' => EditPlannedPost::route('/{record}/edit'),
        ];
    }
}
