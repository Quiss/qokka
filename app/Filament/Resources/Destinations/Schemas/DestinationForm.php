<?php

namespace App\Filament\Resources\Destinations\Schemas;

use App\DestinationPlatform;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DestinationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('publication_id')
                    ->relationship('publication', 'name')
                    ->required(),
                Select::make('platform')
                    ->options([
                        DestinationPlatform::Telegram->value => 'Telegram',
                    ])
                    ->default(DestinationPlatform::Telegram)
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('external_id')
                    ->required()
                    ->helperText('Для Telegram: @username или числовой chat_id.'),
                KeyValue::make('settings')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
