<?php

namespace App\Filament\Resources\SourceChannels\Schemas;

use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SourceChannelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Источник')
                    ->description('Укажите публичную ссылку или ID приватного канала. Доступ проверится через подключённые аккаунты.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('username')
                            ->label('Ссылка или username')
                            ->placeholder('@channel или https://t.me/channel')
                            ->helperText('Для публичного канала. @ и ссылка будут нормализованы автоматически.'),
                        TextInput::make('telegram_peer_id')
                            ->label('Telegram peer ID')
                            ->numeric()
                            ->helperText('Для приватного канала, обычно начинается с -100.'),
                        TextInput::make('title')
                            ->label('Название')
                            ->helperText('Можно оставить пустым — оно заполнится после проверки.'),
                        TextInput::make('weight')
                            ->label('Вес источника')
                            ->required()
                            ->numeric()
                            ->default(1),
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
                        Select::make('sourceGroups')
                            ->label('Группы источников')
                            ->relationship('sourceGroups', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Собирать новости')
                            ->default(true),
                    ]),
            ]);
    }
}
