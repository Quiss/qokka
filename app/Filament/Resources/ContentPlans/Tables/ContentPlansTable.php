<?php

namespace App\Filament\Resources\ContentPlans\Tables;

use App\Actions\BuildContentPlan;
use App\Actions\QueueContentPlanGeneration;
use App\Actions\ReplenishContentPlanCandidates;
use App\Actions\RetryContentPlan;
use App\ContentPlanStatus;
use App\Models\ContentPlan;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('publication.name')
                    ->label('Канал публикаций')
                    ->searchable(),
                TextColumn::make('plan_date')
                    ->label('Дата плана')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Этап')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state->value ?? $state) {
                        'generating' => 'ИИ отбирает новости',
                        'candidate_review' => 'Утверждение плана',
                        'rewriting' => 'Рерайт',
                        'needs_candidates' => 'Нужен добор',
                        'final_review' => 'Проверка рерайта',
                        'ready' => 'Готов к публикации',
                        'active' => 'Публикуется',
                        'completed' => 'Завершён',
                        'failed' => 'Ошибка',
                        default => (string) ($state->value ?? $state),
                    })
                    ->searchable(),
                TextColumn::make('story_candidates_count')
                    ->label('Новостей')
                    ->counts('storyCandidates'),
                TextColumn::make('planned_posts_count')
                    ->label('Публикаций')
                    ->counts('plannedPosts'),
                TextColumn::make('generated_at')
                    ->label('Подборка собрана')
                    ->dateTime()
                    ->placeholder('Нет')
                    ->sortable(),
                TextColumn::make('failure_reason')
                    ->label('Ошибка')
                    ->limit(80)
                    ->placeholder('—')
                    ->tooltip(fn (ContentPlan $record): ?string => $record->failure_reason),
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
                Action::make('generate')
                    ->label('Собрать новости')
                    ->requiresConfirmation()
                    ->visible(fn (ContentPlan $record): bool => $record->generated_at === null && $record->status !== ContentPlanStatus::Generating)
                    ->action(function (ContentPlan $record, QueueContentPlanGeneration $queue): void {
                        $queued = $queue->handle($record);
                        Notification::make()
                            ->title($queued ? 'Подборка поставлена в очередь' : 'Подборка уже собирается')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
                Action::make('build')
                    ->label('Утвердить план и запустить рерайт')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ContentPlan $record): bool => $record->status === ContentPlanStatus::CandidateReview)
                    ->action(function (ContentPlan $record, BuildContentPlan $buildContentPlan): void {
                        $buildContentPlan->handle($record);
                        Notification::make()->title('План утверждён, рерайт запущен')->success()->send();
                    }),
                Action::make('retry')
                    ->label('Повторить')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ContentPlan $record): bool => $record->status === ContentPlanStatus::Failed)
                    ->action(function (ContentPlan $record, RetryContentPlan $retryContentPlan): void {
                        $retryContentPlan->handle($record);
                        Notification::make()->title('Повторный запуск поставлен в очередь')->success()->send();
                    }),
                Action::make('replenish')
                    ->label('Добрать кандидатов')
                    ->icon('heroicon-m-plus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (ContentPlan $record): bool => $record->status === ContentPlanStatus::NeedsCandidates)
                    ->action(function (ContentPlan $record, ReplenishContentPlanCandidates $replenish): void {
                        $queued = $replenish->handle($record);
                        Notification::make()
                            ->title($queued ? 'Добор кандидатов поставлен в очередь' : 'Добор уже выполняется')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
                EditAction::make()
                    ->label('Открыть'),
            ])
            ->defaultSort('plan_date', 'desc');
    }
}
