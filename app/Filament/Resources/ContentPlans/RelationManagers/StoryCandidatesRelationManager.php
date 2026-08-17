<?php

namespace App\Filament\Resources\ContentPlans\RelationManagers;

use App\Actions\ApproveStoryCandidate;
use App\Actions\ReplenishContentPlanCandidates;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Filament\Resources\StoryCandidates\Schemas\StoryCandidateForm;
use App\Filament\Resources\StoryCandidates\Tables\StoryCandidatesTable;
use App\Models\ContentPlan;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use LogicException;

class StoryCandidatesRelationManager extends RelationManager
{
    protected static string $relationship = 'storyCandidates';

    protected static ?string $title = '1. Отбор новостей';

    public function form(Schema $schema): Schema
    {
        return StoryCandidateForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return StoryCandidatesTable::configure($table)
            ->headerActions([
                Action::make('approveAllCandidates')
                    ->label(fn (): string => 'Массово одобрить ('.$this->pendingCandidateCount().')')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Одобрить все оставшиеся новости')
                    ->modalDescription(fn (): string => 'Будут одобрены все новости без решения: '.$this->pendingCandidateCount().'. Уже одобренные и отклонённые новости не изменятся.')
                    ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::CandidateReview
                        && $this->pendingCandidateCount() > 0)
                    ->action(function (ApproveStoryCandidate $approveStoryCandidate): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            $approved = $approveStoryCandidate->approveAllPending($this->contentPlan(), $user);
                            Notification::make()
                                ->title($approved > 0
                                    ? "Одобрено новостей: {$approved}"
                                    : 'Нет новостей для массового одобрения')
                                ->status($approved > 0 ? 'success' : 'warning')
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Новости не одобрены')
                                ->body($this->validationMessage($exception))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('replenishCandidates')
                    ->label(fn (): string => 'Добрать новости ('.$this->candidateDeficit().')')
                    ->icon('heroicon-m-plus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Система добавит до '.$this->candidateDeficit().' новых кандидатов. Уже рассмотренные новости и текущие кандидаты повторно не попадут в подборку.')
                    ->visible(fn (): bool => $this->contentPlan()->status === ContentPlanStatus::CandidateReview
                        && $this->candidateDeficit() > 0)
                    ->action(function (ReplenishContentPlanCandidates $replenish): void {
                        $candidateDeficit = $this->candidateDeficit();
                        $queued = $replenish->handle($this->contentPlan());

                        Notification::make()
                            ->title($queued ? "Добор {$candidateDeficit} новостей поставлен в очередь" : 'Добор уже выполняется')
                            ->status($queued ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    private function pendingCandidateCount(): int
    {
        return $this->contentPlan()->storyCandidates()
            ->where('status', CandidateStatus::Pending)
            ->count();
    }

    private function candidateDeficit(): int
    {
        $contentPlan = $this->contentPlan();
        $activeCandidates = $contentPlan->storyCandidates()
            ->whereIn('status', [
                CandidateStatus::Pending,
                CandidateStatus::Approved,
                CandidateStatus::Reserve,
                CandidateStatus::Selected,
            ])
            ->count();

        return max(0, $contentPlan->candidate_target - $activeCandidates);
    }

    private function contentPlan(): ContentPlan
    {
        $contentPlan = $this->getOwnerRecord();

        if (! $contentPlan instanceof ContentPlan) {
            throw new LogicException('Story candidates relation requires a content plan record.');
        }

        return $contentPlan;
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) ? $message : 'Обновите страницу и повторите попытку.';
    }
}
