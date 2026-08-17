<?php

namespace App\Filament\Resources\ContentPlans\RelationManagers;

use App\Actions\ApprovePlannedPost;
use App\Filament\Resources\PlannedPosts\Schemas\PlannedPostForm;
use App\Filament\Resources\PlannedPosts\Tables\PlannedPostsTable;
use App\Models\ContentPlan;
use App\Models\User;
use App\PlannedPostStatus;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use LogicException;

class PlannedPostsRelationManager extends RelationManager
{
    protected static string $relationship = 'plannedPosts';

    protected static ?string $title = '2. Рерайт и публикация';

    public function form(Schema $schema): Schema
    {
        return PlannedPostForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return PlannedPostsTable::configure($table)
            ->headerActions([
                Action::make('approveAllRewrites')
                    ->label(fn (): string => 'Одобрить все рерайты ('.$this->reviewablePostCount().')')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Одобрить все готовые рерайты')
                    ->modalDescription(function (): string {
                        $reviewable = $this->reviewablePostCount();
                        $risky = $this->riskyPostCount();

                        return "Будут одобрены готовые рерайты: {$reviewable}. С AI-рисками: {$risky}. Отклонённые и ещё не готовые публикации останутся без изменений. Публикации с неподготовленным медиа или недоступным временем будут пропущены.";
                    })
                    ->visible(fn (): bool => $this->reviewablePostCount() > 0)
                    ->action(function (ApprovePlannedPost $approvePlannedPost): void {
                        $user = auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        $result = $approvePlannedPost->approveAllReviewable($this->contentPlan(), $user);
                        $notification = Notification::make()
                            ->title($result['skipped'] > 0
                                ? "Одобрено: {$result['approved']}, пропущено: {$result['skipped']}"
                                : "Одобрено рерайтов: {$result['approved']}")
                            ->status($result['skipped'] > 0 ? 'warning' : 'success');

                        if ($result['errors'] !== []) {
                            $notification->body(Str::limit(implode("\n", $result['errors']), 1000));
                        }

                        $notification->send();
                    }),
            ]);
    }

    private function reviewablePostCount(): int
    {
        return $this->contentPlan()->plannedPosts()
            ->whereIn('status', PlannedPostStatus::reviewableCases())
            ->count();
    }

    private function riskyPostCount(): int
    {
        return $this->contentPlan()->plannedPosts()
            ->whereIn('status', PlannedPostStatus::reviewableCases())
            ->get(['risk_flags'])
            ->filter(fn ($plannedPost): bool => array_values(array_filter($plannedPost->risk_flags ?? [])) !== [])
            ->count();
    }

    private function contentPlan(): ContentPlan
    {
        $contentPlan = $this->getOwnerRecord();

        if (! $contentPlan instanceof ContentPlan) {
            throw new LogicException('Planned posts relation requires a content plan record.');
        }

        return $contentPlan;
    }
}
