<?php

namespace App\Filament\Resources\ContentPlans\Pages;

use App\Actions\BuildContentPlan;
use App\Actions\QueueContentPlanGeneration;
use App\Actions\ReplenishContentPlanCandidates;
use App\Actions\RetryContentPlan;
use App\ContentPlanStatus;
use App\Filament\Resources\ContentPlans\ContentPlanResource;
use App\Models\ContentPlan;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use LogicException;

class EditContentPlan extends EditRecord
{
    protected static string $resource = ContentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Собрать новости')
                ->visible(fn (): bool => $this->contentPlan()->generated_at === null && $this->contentPlan()->status !== ContentPlanStatus::Generating)
                ->action(function (QueueContentPlanGeneration $queue): void {
                    $queued = $queue->handle($this->contentPlan());
                    Notification::make()
                        ->title($queued ? 'Подборка поставлена в очередь' : 'Подборка уже собирается')
                        ->status($queued ? 'success' : 'warning')
                        ->send();
                }),
            Action::make('build')
                ->label('Утвердить план и запустить рерайт')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('В план войдут только одобренные новости. Если их меньше рассчитанного числа слотов, план будет сокращён без добора.')
                ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::CandidateReview)
                ->action(function (BuildContentPlan $buildContentPlan): void {
                    try {
                        $buildContentPlan->handle($this->contentPlan());
                        Notification::make()->title('План утверждён, рерайт запущен')->success()->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('План не утверждён')
                            ->body($this->validationMessage($exception))
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('regenerate')
                ->label('Пересобрать новости')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Одобренные и отклонённые кандидаты сохранятся. Кандидаты без решения будут заменены новой подборкой, а уже рассмотренные источники не вернутся.')
                ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::CandidateReview
                    && $this->contentPlan()->plannedPosts()->doesntExist())
                ->action(function (QueueContentPlanGeneration $queue): void {
                    $queued = $queue->handle($this->contentPlan(), allowRegeneration: true);
                    Notification::make()
                        ->title($queued ? 'Повторная сборка поставлена в очередь' : 'Подборка уже собирается или план уже запущен')
                        ->status($queued ? 'success' : 'warning')
                        ->send();
                }),
            Action::make('retry')
                ->label('Повторить')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::Failed)
                ->action(function (RetryContentPlan $retryContentPlan): void {
                    $retryContentPlan->handle($this->contentPlan());
                    Notification::make()->title('Повторный запуск поставлен в очередь')->success()->send();
                }),
            Action::make('replenish')
                ->label('Добрать кандидатов')
                ->icon('heroicon-m-plus')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Сначала система проверит новые сообщения за 24 часа, затем при необходимости расширит окно до 48 часов. Уже рассмотренные сообщения будут исключены.')
                ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::NeedsCandidates)
                ->action(function (ReplenishContentPlanCandidates $replenish): void {
                    $queued = $replenish->handle($this->contentPlan());
                    Notification::make()
                        ->title($queued ? 'Добор кандидатов поставлен в очередь' : 'Добор уже выполняется')
                        ->status($queued ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    private function contentPlan(): ContentPlan
    {
        $record = $this->getRecord();

        if (! $record instanceof ContentPlan) {
            throw new LogicException('Editorial page requires a content plan record.');
        }

        return $record;
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'Проверьте кандидатов и повторите попытку.';
    }
}
