<?php

namespace App\Filament\Resources\Publications\Tables;

use App\Actions\QueueContentPlanGeneration;
use App\Models\ContentPlan;
use App\Models\Publication;
use App\Services\TelegramPublisher;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class PublicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('destination'))
            ->columns([
                TextColumn::make('name')
                    ->label('Канал публикаций')
                    ->searchable(),
                TextColumn::make('sourceGroup.name')
                    ->label('Группа источников')
                    ->searchable(),
                TextColumn::make('destination.external_id')
                    ->label('Telegram-канал')
                    ->searchable(),
                TextColumn::make('publication_bot')
                    ->label('Бот публикации')
                    ->state(function (Publication $record): string {
                        $username = data_get($record->destination?->settings, 'publisher_bot.username');

                        return filled($username) ? '@'.$username : 'Не проверен';
                    })
                    ->badge(),
                TextColumn::make('planning_time')
                    ->label('Сбор плана')
                    ->time()
                    ->sortable(),
                IconColumn::make('safety_net_enabled')
                    ->label('Страховка')
                    ->boolean(),
                TextColumn::make('safety_net_cutoff_time')
                    ->label('Дедлайн')
                    ->time()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
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
                Action::make('verifyPublisher')
                    ->label('Проверить бота и канал')
                    ->icon('heroicon-o-check-badge')
                    ->action(function (Publication $record, TelegramPublisher $publisher): void {
                        $destination = $record->destination;

                        if ($destination === null) {
                            Notification::make()->title('Канал назначения не задан')->danger()->send();

                            return;
                        }

                        try {
                            $result = $publisher->validateDestination($destination);
                            $details = is_array($result['details'] ?? null) ? $result['details'] : [];
                            $bot = is_array($details['bot'] ?? null) ? $details['bot'] : [];
                            $chat = is_array($details['chat'] ?? null) ? $details['chat'] : [];
                            $settings = is_array($destination->settings) ? $destination->settings : [];
                            $destination->update([
                                'last_verified_at' => now(),
                                'settings' => array_merge($settings, [
                                    'publisher_bot' => [
                                        'id' => $bot['id'] ?? null,
                                        'username' => $bot['username'] ?? null,
                                    ],
                                ]),
                            ]);
                            $botName = filled($bot['username'] ?? null) ? '@'.$bot['username'] : 'Бот';
                            $chatName = $chat['title'] ?? $destination->external_id;
                            Notification::make()
                                ->title("{$botName} готов публиковать в «{$chatName}»")
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Бот не готов к публикации')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('generateNow')
                    ->label('Собрать план сейчас')
                    ->icon('heroicon-o-sparkles')
                    ->action(function (Publication $record, QueueContentPlanGeneration $queue): void {
                        $planDate = CarbonImmutable::now($record->timezone)->addDay()->toDateString();
                        $plan = ContentPlan::query()->firstOrCreate([
                            'publication_id' => $record->id,
                            'plan_date' => $planDate,
                        ]);
                        $queued = $queue->handle($plan);
                        Notification::make()
                            ->title($queued ? 'Подборка поставлена в очередь' : 'Подборка уже существует или собирается')
                            ->status($queued ? 'success' : 'warning')
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
