<?php

namespace App\Filament\Resources\Deliveries\Tables;

use App\Actions\CompleteDeliveryPublication;
use App\Actions\RetryDeliveryPublication;
use App\DeliveryStatus;
use App\Models\Delivery;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('plannedPost.id')
                    ->label('Пост')
                    ->searchable(),
                TextColumn::make('plannedPost.storyCandidate.title')
                    ->label('Новость')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('destination.name')
                    ->label('Канал')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state))
                    ->searchable(),
                TextColumn::make('attempts')
                    ->label('Попыток')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('next_attempt_at')
                    ->label('Следующая попытка')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Опубликовано')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('is_ambiguous')
                    ->label('Неоднозначно')
                    ->boolean(),
                TextColumn::make('last_error')
                    ->label('Последняя ошибка')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap()
                    ->tooltip(fn (Delivery $record): ?string => $record->last_error)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        DeliveryStatus::Pending->value => self::statusLabel(DeliveryStatus::Pending),
                        DeliveryStatus::Publishing->value => self::statusLabel(DeliveryStatus::Publishing),
                        DeliveryStatus::Published->value => self::statusLabel(DeliveryStatus::Published),
                        DeliveryStatus::RetryScheduled->value => self::statusLabel(DeliveryStatus::RetryScheduled),
                        DeliveryStatus::NeedsReview->value => self::statusLabel(DeliveryStatus::NeedsReview),
                        DeliveryStatus::Failed->value => self::statusLabel(DeliveryStatus::Failed),
                        DeliveryStatus::Cancelled->value => self::statusLabel(DeliveryStatus::Cancelled),
                    ]),
            ])
            ->recordActions([
                Action::make('confirmPublished')
                    ->label('Подтвердить публикацию')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Подтвердить, что пост опубликован?')
                    ->modalDescription('Используйте это действие, только если вы нашли публикацию в Telegram. Повторной отправки не будет.')
                    ->modalSubmitActionLabel('Да, пост опубликован')
                    ->visible(fn (Delivery $record): bool => $record->status === DeliveryStatus::NeedsReview)
                    ->action(function (Delivery $record, CompleteDeliveryPublication $complete): void {
                        $user = auth()->user();
                        $complete->handle($record, confirmedBy: $user instanceof User ? $user : null);
                        Notification::make()
                            ->title('Доставка подтверждена как опубликованная')
                            ->success()
                            ->send();
                    }),
                Action::make('retryPublication')
                    ->label('Повторить отправку')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Повторить отправку в Telegram?')
                    ->modalDescription('Если Telegram уже принял предыдущий запрос, повторная отправка создаст дубль. Сначала обязательно проверьте канал.')
                    ->modalSubmitActionLabel('Повторить с риском дубля')
                    ->visible(fn (Delivery $record): bool => in_array($record->status, [DeliveryStatus::NeedsReview, DeliveryStatus::Failed], true))
                    ->action(function (Delivery $record, RetryDeliveryPublication $retry): void {
                        $user = auth()->user();
                        $queued = $retry->handle($record, $user instanceof User ? $user : null);
                        Notification::make()
                            ->title($queued ? 'Повторная отправка поставлена в очередь' : 'Повторная отправка уже запланирована')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function statusLabel(mixed $state): string
    {
        return match ($state->value ?? $state) {
            'pending' => 'Ожидает',
            'publishing' => 'Отправляется',
            'published' => 'Опубликовано',
            'retry_scheduled' => 'Повтор запланирован',
            'needs_review' => 'Нужно проверить',
            'failed' => 'Ошибка',
            'cancelled' => 'Отменено',
            default => (string) ($state->value ?? $state),
        };
    }

    private static function statusColor(mixed $state): string
    {
        return match ($state->value ?? $state) {
            'published' => 'success',
            'needs_review', 'retry_scheduled' => 'warning',
            'failed' => 'danger',
            'publishing' => 'info',
            default => 'gray',
        };
    }
}
