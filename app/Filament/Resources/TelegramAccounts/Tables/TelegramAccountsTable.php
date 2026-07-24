<?php

namespace App\Filament\Resources\TelegramAccounts\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TelegramAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable(),
                TextColumn::make('username')
                    ->label('Telegram')
                    ->formatStateUsing(fn (?string $state): string => $state ? '@'.$state : '—'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                        'pending' => 'Ожидает входа',
                        'authorized' => 'Авторизован',
                        'connected' => 'Подключён',
                        'error' => 'Ошибка',
                        default => (string) ($state->value ?? $state),
                    }),
                TextColumn::make('assigned_source_channels_count')
                    ->label('Источников')
                    ->counts('assignedSourceChannels'),
                TextColumn::make('last_seen_at')
                    ->label('Heartbeat')
                    ->since()
                    ->placeholder('Никогда'),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                TextColumn::make('last_error')
                    ->label('Ошибка')
                    ->limit(60)
                    ->tooltip(fn ($record): ?string => $record->last_error),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Telegram-аккаунты ещё не подключены')
            ->emptyStateDescription('Выполните: vendor/bin/sail artisan telegram:account:authorize main');
    }
}
