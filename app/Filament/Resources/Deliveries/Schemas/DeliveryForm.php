<?php

namespace App\Filament\Resources\Deliveries\Schemas;

use App\DeliveryStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DeliveryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('planned_post_id')
                    ->relationship('plannedPost', 'id')
                    ->required(),
                Select::make('destination_id')
                    ->relationship('destination', 'name')
                    ->required(),
                Select::make('status')
                    ->options(DeliveryStatus::class)
                    ->default('pending')
                    ->required(),
                TextInput::make('external_message_ids'),
                TextInput::make('attempts')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('next_attempt_at'),
                DateTimePicker::make('published_at'),
                Textarea::make('last_error')
                    ->columnSpanFull(),
                TextInput::make('error_context'),
                Toggle::make('is_ambiguous')
                    ->required(),
            ]);
    }
}
