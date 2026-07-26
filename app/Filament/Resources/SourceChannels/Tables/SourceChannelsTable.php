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

                return (new SourceChannel)->scopeWithLastDayStatistics($query);
            })
            ->columns([
                TextColumn::make('title')
                    ->label('Источник')
                    ->searchable(),
                TextColumn::make('username')
                    ->label('Telegram')
                    ->formatStateUsing(fn (?string $state): string => $state ? '@'.$state : '—')
                    ->searchable(),
                TextColumn::make('sourceGroups.name')
                    ->label('Группы')
                    ->badge(),
                TextColumn::make('preferredCollectorTelegramAccount.name')
                    ->label('Предпочтительный сборщик')
                    ->placeholder('Автоматически'),
                TextColumn::make('collectorTelegramAccount.name')
                    ->label('Текущий сборщик')
                    ->placeholder('Не назначен'),
                TextColumn::make('collector_status')
                    ->label('Статус сборщика')
                    ->state(fn (SourceChannel $record): string => $record->collectorStatus())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'automatic' => 'Автоматически',
                        'preferred' => 'Предпочтительный',
                        'fallback' => 'Резервный',
                        default => 'Нет доступного',
                    })
                    ->badge()
                    ->color(fn (SourceChannel $record): string => match ($record->collectorStatus()) {
                        'preferred' => 'success',
                        'fallback' => 'warning',
                        'automatic' => 'info',
                        default => 'danger',
                    })
                    ->description(function (SourceChannel $record): ?string {
                        $lastError = $record->collectorLastError();

                        return $lastError ? Str::limit($lastError, 100) : null;
                    })
                    ->tooltip(fn (SourceChannel $record): ?string => $record->collectorLastError())
                    ->wrap(),
                TextColumn::make('weight')
                    ->label('Вес')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('posts_last_day_count')
                    ->label('Постов · 24ч')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('views_last_day')
                    ->label('Просмотры · 24ч')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reactions_last_day')
                    ->label('Реакции · 24ч')
                    ->numeric()
                    ->sortable(),
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
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                TextColumn::make('last_event_at')
                    ->label('Последняя новость')
                    ->since()
                    ->placeholder('Нет событий')
                    ->sortable(),
                TextColumn::make('last_backfilled_at')
                    ->label('Статистика обновлена')
                    ->since()
                    ->placeholder('Ещё не синхронизирована')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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
