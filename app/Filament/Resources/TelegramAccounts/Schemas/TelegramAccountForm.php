<?php

namespace App\Filament\Resources\TelegramAccounts\Schemas;

use App\Models\TelegramAccount;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TelegramAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Telegram-аккаунт')
                    ->description('Авторизация выполняется безопасно через CLI-команду telegram:account:authorize.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->label('Использовать для сбора')
                            ->default(true),
                        Placeholder::make('identity')
                            ->label('Аккаунт')
                            ->content(fn (TelegramAccount $record): string => $record->username ? '@'.$record->username : ($record->phone_hint ?? 'Не авторизован')),
                        Placeholder::make('status_label')
                            ->label('Состояние')
                            ->content(fn (TelegramAccount $record): string => $record->status->value),
                        Placeholder::make('last_seen_label')
                            ->label('Последний heartbeat')
                            ->content(fn (TelegramAccount $record): string => $record->last_seen_at?->diffForHumans() ?? 'Никогда'),
                        Placeholder::make('last_error')
                            ->label('Последняя ошибка')
                            ->content(fn (TelegramAccount $record): string => $record->last_error ?? 'Нет')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
