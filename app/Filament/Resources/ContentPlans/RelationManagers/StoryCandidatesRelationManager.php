<?php

namespace App\Filament\Resources\ContentPlans\RelationManagers;

use App\Actions\ReplenishContentPlanCandidates;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Filament\Resources\StoryCandidates\Schemas\StoryCandidateForm;
use App\Filament\Resources\StoryCandidates\Tables\StoryCandidatesTable;
use App\Models\ContentPlan;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
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
}
