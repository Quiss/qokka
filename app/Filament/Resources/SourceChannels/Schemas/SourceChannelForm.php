<?php

namespace App\Filament\Resources\SourceChannels\Schemas;

use App\Models\Source;
use App\Models\TelegramAccount;
use App\SourceType;
use App\TelegramAccountStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SourceChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        $normalizeUsername = fn (mixed $state): ?string => filled($state)
            ? Source::normalizeUsername((string) $state)
            : null;

        return $schema
            ->components([
                Select::make('type')
                    ->label('Тип источника')
                    ->options([
                        SourceType::Telegram->value => 'Telegram',
                        SourceType::JsonCollection->value => 'JSON-подборки',
                    ])
                    ->default(SourceType::Telegram->value)
                    ->required()
                    ->live()
                    ->disabled(fn (?Source $record): bool => $record !== null)
                    ->dehydrated(),
                Section::make('Источник')
                    ->description('Общие настройки источника и его привязка к группам публикаций.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Название')
                            ->required(fn (Get $get): bool => $get('type') === SourceType::JsonCollection->value)
                            ->helperText('Для Telegram можно оставить пустым — название заполнится после проверки.'),
                        TextInput::make('weight')
                            ->label('Вес источника')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(1),
                        Select::make('sourceGroups')
                            ->label('Группы источников')
                            ->relationship('sourceGroups', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Собирать материалы')
                            ->default(true),
                    ]),
                Section::make('Telegram')
                    ->description('Укажите публичную ссылку или ID приватного канала. Доступ проверится через подключённые аккаунты.')
                    ->visible(fn (Get $get): bool => $get('type') === SourceType::Telegram->value)
                    ->columns(2)
                    ->schema([
                        TextInput::make('username')
                            ->label('Ссылка или username')
                            ->placeholder('@channel или https://t.me/channel')
                            ->helperText('Для публичного канала. @ и ссылка будут нормализованы автоматически.')
                            ->mutateStateForValidationUsing($normalizeUsername)
                            ->dehydrateStateUsing($normalizeUsername)
                            ->unique()
                            ->validationMessages([
                                'unique' => 'Этот Telegram-канал уже добавлен в источники.',
                            ]),
                        TextInput::make('telegram_peer_id')
                            ->label('Telegram peer ID')
                            ->numeric()
                            ->helperText('Для приватного канала, обычно начинается с -100.')
                            ->unique()
                            ->validationMessages([
                                'unique' => 'Источник с таким Telegram peer ID уже добавлен.',
                            ]),
                        Select::make('preferred_collector_telegram_account_id')
                            ->label('Предпочтительный сборщик')
                            ->relationship(
                                name: 'preferredCollectorTelegramAccount',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('is_active', true)
                                    ->whereIn('status', [
                                        TelegramAccountStatus::Authorized,
                                        TelegramAccountStatus::Connected,
                                    ])
                                    ->orderBy('name'),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (TelegramAccount $record): string => sprintf(
                                    '%s%s · %s',
                                    $record->name,
                                    $record->username ? ' (@'.$record->username.')' : '',
                                    match ($record->status) {
                                        TelegramAccountStatus::Connected => 'подключён',
                                        TelegramAccountStatus::Authorized => 'авторизован',
                                        default => $record->status->value,
                                    },
                                ),
                            )
                            ->placeholder('Автоматически')
                            ->helperText('При недоступности выбранного аккаунта система временно назначит резервный и вернётся к этому аккаунту после восстановления.')
                            ->searchable()
                            ->preload(),
                    ]),
                Section::make('JSON API')
                    ->description('Один объект collections[] будет сохранён как одна атомарная подборка.')
                    ->visible(fn (Get $get): bool => $get('type') === SourceType::JsonCollection->value)
                    ->columns(2)
                    ->schema([
                        TextInput::make('endpoint_url')
                            ->label('Endpoint URL')
                            ->placeholder('https://example.com/api/v1/publications')
                            ->required()
                            ->url()
                            ->startsWith('https://')
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        TextInput::make('settings.lookback_hours')
                            ->label('Период, часов')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(24),
                        TextInput::make('settings.limit')
                            ->label('Лимит подборок')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(100),
                        TextInput::make('credentials.authorization')
                            ->label('Authorization')
                            ->password()
                            ->revealable()
                            ->maxLength(2048)
                            ->formatStateUsing(fn (): null => null)
                            ->helperText('Передаётся как есть, без автоматического префикса Bearer. Оставьте пустым, чтобы сохранить текущий токен.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
