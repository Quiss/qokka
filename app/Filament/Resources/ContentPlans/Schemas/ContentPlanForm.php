<?php

namespace App\Filament\Resources\ContentPlans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class ContentPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('publication_id')
                    ->label('Канал публикаций')
                    ->relationship('publication', 'name')
                    ->disabled()
                    ->dehydrated(false)
                    ->required(),
                DatePicker::make('plan_date')
                    ->label('Дата плана')
                    ->disabled()
                    ->dehydrated(false)
                    ->required()
                    ->default(today()->addDay()),
            ]);
    }
}
