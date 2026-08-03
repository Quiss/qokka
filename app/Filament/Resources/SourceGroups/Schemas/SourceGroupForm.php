<?php

namespace App\Filament\Resources\SourceGroups\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SourceGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Название группы')
                    ->required(),
                Textarea::make('description')
                    ->label('Описание')
                    ->columnSpanFull(),
                Select::make('sources')
                    ->label('Источники')
                    ->relationship('sources', 'title')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Группа активна')
                    ->default(true),
            ]);
    }
}
