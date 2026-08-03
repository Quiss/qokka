<?php

namespace App\Filament\Resources\StoryCandidates\Tables;

use App\Actions\ApproveStoryCandidate;
use App\Actions\PlaceCandidateInPlan;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Models\MediaAsset;
use App\Models\StoryCandidate;
use App\Models\User;
use App\RiskFlagLabels;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StoryCandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'contentPlan',
                'sourcePosts.source',
                'sourcePosts.mediaAssets',
            ]))
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderByRaw(
                        'CASE WHEN status = ? THEN 1 ELSE 0 END',
                        [CandidateStatus::Rejected->value],
                    )
                    ->orderByDesc('score');
            })
            ->recordClasses(fn (StoryCandidate $record): ?string => $record->status === CandidateStatus::Rejected
                ? 'bg-danger-50/70 dark:bg-danger-500/10'
                : null)
            ->columns([
                Split::make([
                    ImageColumn::make('media_preview')
                        ->label('Медиа')
                        ->state(fn (StoryCandidate $record): ?string => self::previewUrl($record))
                        ->defaultImageUrl(self::mediaPlaceholder())
                        ->alt(fn (StoryCandidate $record): string => 'Медиа новости «'.$record->title.'»')
                        ->imageSize(72)
                        ->square()
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('title')
                            ->label('Новость')
                            ->weight(FontWeight::SemiBold)
                            ->searchable()
                            ->wrap(),
                        TextColumn::make('summary')
                            ->label('Кратко')
                            ->limit(180)
                            ->wrap()
                            ->color('gray')
                            ->placeholder('Краткое описание отсутствует'),
                        TextColumn::make('source_count')
                            ->label('Источники')
                            ->state(fn (StoryCandidate $record): string => $record->sourcePosts->count().' источников')
                            ->icon('heroicon-m-link')
                            ->color('gray'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('score')
                            ->label('Оценка ИИ')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 0).' / 100')
                            ->color(fn (mixed $state): string => match (true) {
                                (float) $state >= 80 => 'success',
                                (float) $state >= 60 => 'warning',
                                default => 'gray',
                            })
                            ->sortable(),
                        TextColumn::make('status')
                            ->label('Решение')
                            ->badge()
                            ->formatStateUsing(self::statusLabel(...))
                            ->color(self::statusColor(...))
                            ->searchable(),
                        TextColumn::make('risk_flags')
                            ->label('Риски')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => RiskFlagLabels::label($state))
                            ->placeholder('Рисков нет')
                            ->limitList(2),
                    ])->space(1)->grow(false),
                ])->from('md'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalHeading(fn (StoryCandidate $record): string => $record->title)
                    ->schema([
                        View::make('filament.content-plans.story-candidate-details'),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StoryCandidate $record): bool => $record->status === CandidateStatus::Pending && $record->contentPlan->status === ContentPlanStatus::CandidateReview)
                    ->action(function (StoryCandidate $record, ApproveStoryCandidate $approveStoryCandidate): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $approveStoryCandidate->approve($record, $user);
                            Notification::make()->title('Новость добавлена в план')->success()->send();
                        }
                    }),
                Action::make('place')
                    ->label('В свободный слот')
                    ->icon('heroicon-m-arrow-right-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StoryCandidate $record): bool => $record->status === CandidateStatus::Pending && $record->contentPlan->status === ContentPlanStatus::NeedsCandidates)
                    ->action(function (StoryCandidate $record, PlaceCandidateInPlan $placeCandidateInPlan): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $placeCandidateInPlan->handle($record, $user);
                            Notification::make()->title('Кандидат занял свободный слот, рерайт запущен')->success()->send();
                        }
                    }),
                ActionGroup::make([
                    Action::make('reserve')
                        ->label('В резерв')
                        ->icon('heroicon-m-archive-box')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->visible(fn (StoryCandidate $record): bool => $record->status === CandidateStatus::Pending && $record->contentPlan->status === ContentPlanStatus::NeedsCandidates)
                        ->action(function (StoryCandidate $record, ApproveStoryCandidate $approveStoryCandidate): void {
                            $user = auth()->user();

                            if ($user instanceof User) {
                                $approveStoryCandidate->reserve($record, $user);
                                Notification::make()->title('Кандидат добавлен в резерв')->success()->send();
                            }
                        }),
                    Action::make('reject')
                        ->label('Отклонить')
                        ->icon('heroicon-m-x-mark')
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')->label('Причина')->required(),
                        ])
                        ->visible(fn (StoryCandidate $record): bool => in_array($record->status, [CandidateStatus::Pending, CandidateStatus::Reserve], true))
                        ->action(function (StoryCandidate $record, array $data, ApproveStoryCandidate $approveStoryCandidate): void {
                            $user = auth()->user();

                            if ($user instanceof User) {
                                $approveStoryCandidate->reject($record, $user, $data['reason']);
                                Notification::make()->title('Новость отклонена')->success()->send();
                            }
                        }),
                    EditAction::make()->label('Редактировать'),
                ])->icon('heroicon-m-ellipsis-horizontal'),
            ]);
    }

    private static function previewUrl(StoryCandidate $record): ?string
    {
        $primarySource = $record->sourcePosts->firstWhere('pivot.is_primary', true)
            ?? $record->sourcePosts->first();

        return $primarySource?->mediaAssets
            ->map(fn (MediaAsset $asset): ?string => $asset->previewUrl())
            ->first(fn (?string $url): bool => filled($url));
    }

    private static function statusLabel(mixed $state): string
    {
        return match ($state->value ?? $state) {
            'pending' => 'Ожидает решения',
            'approved' => 'Одобрена',
            'rejected' => 'Отклонена',
            'selected' => 'В плане',
            'reserve' => 'Резерв',
            default => (string) ($state->value ?? $state),
        };
    }

    private static function statusColor(mixed $state): string
    {
        return match ($state->value ?? $state) {
            'approved', 'selected' => 'success',
            'rejected' => 'danger',
            'reserve' => 'warning',
            default => 'gray',
        };
    }

    private static function mediaPlaceholder(): string
    {
        return 'data:image/svg+xml,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#e4e4e7"/><path d="M28 31h40v34H28z" fill="none" stroke="#71717a" stroke-width="3"/><path d="m32 59 10-11 8 8 6-7 8 10" fill="none" stroke="#71717a" stroke-width="3"/></svg>',
        );
    }
}
