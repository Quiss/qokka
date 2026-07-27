<?php

namespace App\Filament\Resources\ContentPlans\Tables;

use App\Actions\BuildContentPlan;
use App\Actions\DeleteContentPlan;
use App\Actions\QueueContentPlanGeneration;
use App\Actions\ReplenishContentPlanCandidates;
use App\Actions\RetryContentPlan;
use App\ContentPlanStatus;
use App\Models\ContentPlan;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ContentPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'storyCandidates',
                'plannedPosts',
            ]))
            ->columns([
                Grid::make([
                    'md' => 2,
                    'xl' => 3,
                ])->schema([
                    Stack::make([
                        TextColumn::make('publication.name')
                            ->label('Канал публикаций')
                            ->weight(FontWeight::SemiBold)
                            ->searchable(),
                        TextColumn::make('plan_date')
                            ->label('Дата плана')
                            ->date('d.m.Y')
                            ->icon('heroicon-m-calendar-days')
                            ->color('gray')
                            ->sortable(),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('status')
                            ->label('Этап')
                            ->badge()
                            ->searchable(),
                        TextColumn::make('plan_summary')
                            ->label('Состав плана')
                            ->state(fn (ContentPlan $record): string => "{$record->story_candidates_count} кандидатов · {$record->planned_posts_count} публикаций")
                            ->icon('heroicon-m-document-text')
                            ->color('gray'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('generated_at')
                            ->label('Подборка собрана')
                            ->dateTime('d.m.Y H:i')
                            ->prefix('Собрано: ')
                            ->placeholder('Подборка ещё не собрана')
                            ->color('gray')
                            ->sortable(),
                        TextColumn::make('safety_net_started_at')
                            ->label('Страховочная автопубликация')
                            ->dateTime('d.m.Y H:i')
                            ->prefix('Страховка: ')
                            ->placeholder('Ручной режим')
                            ->color('warning')
                            ->sortable(),
                        TextColumn::make('failure_reason')
                            ->label('Ошибка')
                            ->limit(70)
                            ->color('danger')
                            ->tooltip(fn (ContentPlan $record): ?string => $record->failure_reason),
                    ])->space(1),
                ]),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Открыть')
                        ->icon('heroicon-m-pencil-square'),
                    Action::make('generate')
                        ->label('Собрать новости')
                        ->icon('heroicon-m-sparkles')
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
                        ->icon('heroicon-m-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('В план войдут только одобренные новости. Если их меньше рассчитанного числа слотов, план будет сокращён без добора.')
                        ->visible(fn (ContentPlan $record): bool => $record->status === ContentPlanStatus::CandidateReview)
                        ->action(function (ContentPlan $record, BuildContentPlan $buildContentPlan): void {
                            try {
                                $buildContentPlan->handle($record);
                                Notification::make()->title('План утверждён, рерайт запущен')->success()->send();
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title('План не утверждён')
                                    ->body(self::validationMessage($exception))
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Action::make('retry')
                        ->label('Повторить')
                        ->icon('heroicon-m-arrow-path')
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
                ]),
                DeleteAction::make()
                    ->label('Удалить план')
                    ->iconButton()
                    ->tooltip('Удалить план')
                    ->modalHeading('Удалить контент-план?')
                    ->modalDescription('Будут удалены кандидаты, подготовленные публикации, доставки и история работы ИИ. Уже отправленные сообщения останутся в Telegram.')
                    ->modalSubmitActionLabel('Удалить план')
                    ->successNotificationTitle('Контент-план удалён')
                    ->using(fn (ContentPlan $record, DeleteContentPlan $deleteContentPlan): bool => $deleteContentPlan->handle($record)),
            ])
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->defaultSort(function (Builder $query): Builder {
                $statusOrder = ContentPlanStatus::editorialPriority();
                $bindings = array_map(
                    fn (ContentPlanStatus $status): string => $status->value,
                    $statusOrder,
                );

                return $query
                    ->orderByRaw(
                        'CASE status
                            WHEN ? THEN 10
                            WHEN ? THEN 20
                            WHEN ? THEN 30
                            WHEN ? THEN 40
                            WHEN ? THEN 50
                            WHEN ? THEN 60
                            WHEN ? THEN 70
                            WHEN ? THEN 80
                            WHEN ? THEN 90
                            WHEN ? THEN 100
                            ELSE 110
                        END',
                        $bindings,
                    )
                    ->orderByDesc('plan_date')
                    ->orderByDesc('id');
            });
    }

    private static function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'Проверьте кандидатов и повторите попытку.';
    }
}
