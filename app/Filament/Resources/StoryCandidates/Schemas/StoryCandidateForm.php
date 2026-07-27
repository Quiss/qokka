<?php

namespace App\Filament\Resources\StoryCandidates\Schemas;

use App\CandidateStatus;
use App\RiskFlagLabels;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StoryCandidateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('content_plan_id')
                    ->relationship('contentPlan', 'id')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('title')
                    ->required(),
                Textarea::make('summary')
                    ->columnSpanFull(),
                TextInput::make('score')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false),
                KeyValue::make('score_breakdown')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('ai_reason')
                    ->columnSpanFull(),
                TagsInput::make('risk_flags')
                    ->label('Риски')
                    ->formatStateUsing(fn (?array $state): array => RiskFlagLabels::labels($state ?? []))
                    ->disabled()
                    ->dehydrated(false),
                Select::make('status')
                    ->options(CandidateStatus::class)
                    ->disabled()
                    ->dehydrated(false),
            ]);
    }
}
