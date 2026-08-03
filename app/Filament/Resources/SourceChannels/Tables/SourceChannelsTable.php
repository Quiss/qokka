<?php

namespace App\Filament\Resources\SourceChannels\Tables;

use App\Jobs\SyncJsonCollectionSourceJob;
use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\Source;
use App\SourceType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class SourceChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $query->with([
                    'collectorTelegramAccount:id,name',
                    'preferredCollectorTelegramAccount:id,name',
                    'telegramAccounts:id,name',
                ]);

                return (new Source)->scopeWithLastDayStatistics($query);
            })
            ->columns([
                TextColumn::make('title')
                    ->label('Источник')
                    ->description(fn (Source $record): string => $record->isTelegram()
                        ? ($record->username ? '@'.$record->username : 'Приватный Telegram-канал')
                        : (string) parse_url((string) $record->endpoint_url, PHP_URL_HOST))
                    ->searchable(['title', 'username', 'endpoint_url'])
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (SourceType $state): string => match ($state) {
                        SourceType::Telegram => 'Telegram',
                        SourceType::JsonCollection => 'JSON',
                    })
                    ->color(fn (SourceType $state): string => match ($state) {
                        SourceType::Telegram => 'info',
                        SourceType::JsonCollection => 'success',
                    }),
                TextColumn::make('sourceGroups.name')
                    ->label('Группы')
                    ->badge()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('collector')
                    ->label('Сборщик')
                    ->state(fn (Source $record): string => $record->isTelegram()
                        ? $record->collectorStatus()
                        : 'not_applicable')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'automatic' => 'Автоматически',
                        'preferred' => 'Предпочтительный',
                        'fallback' => 'Резервный',
                        'not_applicable' => 'Не требуется',
                        default => 'Нет доступного',
                    })
                    ->badge()
                    ->toggleable()
                    ->color(fn (Source $record): string => match ($record->isTelegram() ? $record->collectorStatus() : 'not_applicable') {
                        'preferred' => 'success',
                        'fallback' => 'warning',
                        'automatic' => 'info',
                        'not_applicable' => 'gray',
                        default => 'danger',
                    })
                    ->description(function (Source $record): string {
                        if (! $record->isTelegram()) {
                            return $record->last_sync_error
                                ? 'Ошибка: '.Str::limit($record->last_sync_error, 80)
                                : 'Фоновая HTTP-синхронизация';
                        }

                        $currentCollector = $record->collector_telegram_account_id === null
                            ? 'не назначен'
                            : $record->collectorTelegramAccount->name;
                        $preferredCollector = $record->preferred_collector_telegram_account_id === null
                            ? 'автовыбор'
                            : $record->preferredCollectorTelegramAccount->name;
                        $lastError = $record->collectorLastError();

                        $description = "Текущий: {$currentCollector} · Предпочтительный: {$preferredCollector}";

                        return $lastError
                            ? $description.' · Ошибка: '.Str::limit($lastError, 60)
                            : $description;
                    })
                    ->tooltip(fn (Source $record): ?string => $record->collectorLastError())
                    ->wrap(),
                ViewColumn::make('statistics')
                    ->label('Статистика · 24ч')
                    ->view('filament.tables.columns.source-channel-statistics')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('last_event_at')
                    ->label('Последний материал')
                    ->since()
                    ->placeholder('Нет событий')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_synced_at')
                    ->label('JSON синхронизирован')
                    ->since()
                    ->placeholder('Ещё не синхронизирован')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('weight')
                    ->label('Вес')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_backfilled_at')
                    ->label('Статистика обновлена')
                    ->since()
                    ->placeholder('Ещё не синхронизирована')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Изменён')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('sourceGroups')
                    ->label('Группы')
                    ->relationship('sourceGroups', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        SourceType::Telegram->value => 'Telegram',
                        SourceType::JsonCollection->value => 'JSON-подборки',
                    ]),
            ])
            ->reorderableColumns()
            ->deferColumnManager(false)
            ->columnManagerColumns(2)
            ->recordActions([
                Action::make('syncStatistics')
                    ->label('Обновить статистику')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Source $record): bool => $record->isTelegram() && $record->collector_telegram_account_id !== null)
                    ->action(function (Source $record): void {
                        SyncSourceChannelStatisticsJob::dispatch($record->id)->onQueue('telegram');
                        Notification::make()
                            ->title('Статистика за 24 часа поставлена в очередь')
                            ->success()
                            ->send();
                    }),
                Action::make('verify')
                    ->label('Проверить доступ')
                    ->icon('heroicon-o-signal')
                    ->visible(fn (Source $record): bool => $record->isTelegram())
                    ->action(function (Source $record): void {
                        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
                        Notification::make()
                            ->title('Проверка источника поставлена в очередь')
                            ->success()
                            ->send();
                    }),
                Action::make('syncJson')
                    ->label('Синхронизировать')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Source $record): bool => $record->isJsonCollection())
                    ->action(function (Source $record): void {
                        SyncJsonCollectionSourceJob::dispatch($record->id)->onQueue('ingest');
                        Notification::make()
                            ->title('JSON-источник поставлен в очередь')
                            ->success()
                            ->send();
                    }),
                Action::make('clearAuthorization')
                    ->label('Удалить Authorization')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Source $record): bool => $record->isJsonCollection() && $record->authorization() !== null)
                    ->action(function (Source $record): void {
                        $record->update(['credentials' => null]);
                        Notification::make()
                            ->title('Authorization удалён')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
