<?php

namespace App\Filament\Resources\SourceChannels\Tables;

use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
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

                return (new SourceChannel)->scopeWithLastDayStatistics($query);
            })
            ->columns([
                TextColumn::make('title')
                    ->label('Источник')
                    ->description(fn (SourceChannel $record): string => $record->username ? '@'.$record->username : 'Telegram не указан')
                    ->searchable(['title', 'username'])
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sourceGroups.name')
                    ->label('Группы')
                    ->badge()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('collector')
                    ->label('Сборщик')
                    ->state(fn (SourceChannel $record): string => $record->collectorStatus())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'automatic' => 'Автоматически',
                        'preferred' => 'Предпочтительный',
                        'fallback' => 'Резервный',
                        default => 'Нет доступного',
                    })
                    ->badge()
                    ->toggleable()
                    ->color(fn (SourceChannel $record): string => match ($record->collectorStatus()) {
                        'preferred' => 'success',
                        'fallback' => 'warning',
                        'automatic' => 'info',
                        default => 'danger',
                    })
                    ->description(function (SourceChannel $record): string {
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
                    ->tooltip(fn (SourceChannel $record): ?string => $record->collectorLastError())
                    ->wrap(),
                TextColumn::make('statistics')
                    ->label('Статистика · 24ч')
                    ->state(fn (SourceChannel $record): string => implode(' · ', [
                        Number::format((int) $record->posts_last_day_count, locale: 'ru').' постов',
                        Number::format((int) $record->views_last_day, locale: 'ru').' просмотров',
                        Number::format((int) $record->reactions_last_day, locale: 'ru').' реакций',
                    ]))
                    ->icon('heroicon-m-chart-bar')
                    ->wrap()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('last_event_at')
                    ->label('Последняя новость')
                    ->since()
                    ->placeholder('Нет событий')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('weight')
                    ->label('Вес')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('posts_last_day_count')
                    ->label('Постов · 24ч')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('views_last_day')
                    ->label('Просмотры · 24ч')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reactions_last_day')
                    ->label('Реакции · 24ч')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('forwards_last_day')
                    ->label('Пересылки · 24ч')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('comments_last_day')
                    ->label('Комментарии · 24ч')
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
            ])
            ->reorderableColumns()
            ->deferColumnManager(false)
            ->columnManagerColumns(2)
            ->recordActions([
                Action::make('syncStatistics')
                    ->label('Обновить статистику')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (SourceChannel $record): bool => $record->collector_telegram_account_id !== null)
                    ->action(function (SourceChannel $record): void {
                        SyncSourceChannelStatisticsJob::dispatch($record->id)->onQueue('telegram');
                        Notification::make()
                            ->title('Статистика за 24 часа поставлена в очередь')
                            ->success()
                            ->send();
                    }),
                Action::make('verify')
                    ->label('Проверить доступ')
                    ->icon('heroicon-o-signal')
                    ->action(function (SourceChannel $record): void {
                        VerifySourceChannelAccessJob::dispatch($record->id)->onQueue('telegram');
                        Notification::make()
                            ->title('Проверка источника поставлена в очередь')
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
