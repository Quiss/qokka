<?php

namespace App\Filament\Resources\PlannedPosts\Tables;

use App\Actions\ApprovePlannedPost;
use App\Actions\RequestPlannedPostRewrite;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\PlannedPostRevision;
use App\Models\User;
use App\ModerationActionType;
use App\PlannedPostStatus;
use App\Services\PlannedPostMediaManager;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ViewField;
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
use Illuminate\Support\Str;

class PlannedPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'contentPlan.publication:id,timezone',
                'mediaAssets',
                'revisions.requestedBy',
                'storyCandidate.sourcePosts.sourceChannel',
                'storyCandidate.sourcePosts.mediaAssets',
            ]))
            ->columns([
                Split::make([
                    ImageColumn::make('media_preview')
                        ->label('Медиа')
                        ->state(fn (PlannedPost $record): ?string => $record->mediaAssets->first()?->previewUrl())
                        ->defaultImageUrl('data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#e4e4e7"/><path d="M28 31h40v34H28z" fill="none" stroke="#71717a" stroke-width="3"/><path d="m32 59 10-11 8 8 6-7 8 10" fill="none" stroke="#71717a" stroke-width="3"/></svg>'))
                        ->alt(fn (PlannedPost $record): string => 'Медиа для публикации «'.$record->storyCandidate->title.'»')
                        ->imageSize(72)
                        ->square()
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('storyCandidate.title')
                            ->label('Новость')
                            ->weight(FontWeight::SemiBold)
                            ->searchable()
                            ->wrap(),
                        TextColumn::make('text')
                            ->label('Готовый текст')
                            ->markdown()
                            ->limit(180)
                            ->wrap()
                            ->color('gray')
                            ->placeholder('Рерайт ещё выполняется'),
                        TextColumn::make('storyCandidate.source_posts_count')
                            ->label('Источники')
                            ->state(fn (PlannedPost $record): string => $record->storyCandidate->sourcePosts->count().' источников')
                            ->icon('heroicon-m-link')
                            ->color('gray'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('status')
                            ->label('Этап')
                            ->badge()
                            ->formatStateUsing(self::statusLabel(...)),
                        TextColumn::make('scheduled_at')
                            ->label('Время публикации')
                            ->dateTime(
                                'd.m, H:i',
                                fn (PlannedPost $record): string => $record->publicationTimezone(),
                            )
                            ->icon('heroicon-m-clock')
                            ->sortable(),
                        TextColumn::make('risk_flags')
                            ->label('Риски')
                            ->badge()
                            ->placeholder('Рисков нет')
                            ->limitList(2),
                    ])->space(1)->grow(false),
                ])->from('md'),
                TextColumn::make('failure_reason')
                    ->label('Ошибка')
                    ->color('danger')
                    ->wrap()
                    ->placeholder(''),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Открыть')
                    ->icon('heroicon-m-eye')
                    ->color('gray')
                    ->slideOver()
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalHeading(fn (PlannedPost $record): string => $record->storyCandidate->title)
                    ->fillForm(fn (PlannedPost $record): array => [
                        'text' => $record->text,
                        'media_asset_ids' => $record->mediaAssets
                            ->pluck('origin_media_asset_id')
                            ->filter()
                            ->values()
                            ->all(),
                    ])
                    ->schema([
                        MarkdownEditor::make('text')
                            ->label('Текст публикации')
                            ->toolbarButtons([
                                ['bold', 'italic', 'strike', 'link'],
                                ['undo', 'redo'],
                            ])
                            ->required()
                            ->disabled(fn (PlannedPost $record): bool => self::isImmutable($record))
                            ->columnSpanFull(),
                        ViewField::make('media_asset_ids')
                            ->label('Какие фото и видео пойдут в публикацию')
                            ->helperText('Нажмите на карточки, чтобы выбрать файлы. В верхнем списке перетащите выбранные медиа в нужном порядке. Максимум 10.')
                            ->view('filament.forms.components.media-picker')
                            ->viewData(fn (PlannedPost $record, PlannedPostMediaManager $mediaManager): array => [
                                'assets' => $mediaManager->availableAssets($record),
                            ])
                            ->rules(['array', 'max:10'])
                            ->disabled(fn (PlannedPost $record): bool => self::isImmutable($record))
                            ->columnSpanFull(),
                        View::make('filament.content-plans.planned-post-details'),
                    ])
                    ->modalSubmitActionLabel('Сохранить')
                    ->action(function (
                        PlannedPost $record,
                        array $data,
                        PlannedPostMediaManager $mediaManager,
                    ): void {
                        if (self::isImmutable($record)) {
                            return;
                        }

                        $record->update(['text' => $data['text']]);
                        $mediaManager->replaceSelection($record, $data['media_asset_ids'] ?? []);
                        $user = auth()->user();

                        if ($user instanceof User) {
                            ModerationAction::create([
                                'user_id' => $user->id,
                                'subject_type' => $record::class,
                                'subject_id' => $record->id,
                                'action' => ModerationActionType::EditPost,
                                'metadata' => ['media_asset_ids' => $data['media_asset_ids'] ?? []],
                            ]);
                        }

                        Notification::make()->title('Текст и медиа сохранены')->success()->send();
                    }),
                Action::make('approve')
                    ->label('Одобрить')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->modalHidden(fn (PlannedPost $record): bool => ($record->risk_flags ?? []) === [])
                    ->modalHeading('Одобрить публикацию с рисками')
                    ->modalDescription(fn (PlannedPost $record): string => self::riskDescription($record))
                    ->modalSubmitActionLabel('Всё равно одобрить')
                    ->schema([
                        Textarea::make('override_reason')
                            ->label('Комментарий редактора')
                            ->placeholder('Например: проверил источники, спорная деталь не вошла в текст')
                            ->helperText('Необязательно. Можно оставить пустым, если вы проверили публикацию и готовы её одобрить.'),
                    ])
                    ->visible(fn (PlannedPost $record): bool => in_array($record->status, [PlannedPostStatus::FinalReview, PlannedPostStatus::Blocked, PlannedPostStatus::NeedsReschedule], true))
                    ->action(function (PlannedPost $record, array $data, ApprovePlannedPost $approvePlannedPost): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $approvePlannedPost->approve($record, $user, $data['override_reason'] ?? null);
                            Notification::make()->title('Публикация одобрена')->success()->send();
                        }
                    }),
                Action::make('rewrite')
                    ->label('Рерайт ещё раз')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->schema([
                        Textarea::make('instruction')
                            ->label('Инструкция редактора')
                            ->placeholder('Например: сделай короче и добавь больше конкретики')
                            ->helperText('Необязательно. Предыдущая версия останется в истории.'),
                    ])
                    ->visible(fn (PlannedPost $record): bool => ! self::isImmutable($record))
                    ->action(function (PlannedPost $record, array $data, RequestPlannedPostRewrite $rewrite): void {
                        $user = auth()->user();

                        if ($user instanceof User) {
                            $rewrite->handle($record, $user, $data['instruction'] ?? null);
                            Notification::make()->title('Повторный рерайт поставлен в очередь')->success()->send();
                        }
                    }),
                ActionGroup::make([
                    Action::make('restore_revision')
                        ->label('Восстановить версию')
                        ->icon('heroicon-m-clock')
                        ->schema([
                            Select::make('revision_id')
                                ->label('Версия')
                                ->options(fn (PlannedPost $record): array => $record->revisions
                                    ->mapWithKeys(fn (PlannedPostRevision $revision): array => [
                                        $revision->id => 'Версия '.$revision->version.' · '.$revision->created_at->format('d.m H:i'),
                                    ])
                                    ->all())
                                ->required(),
                        ])
                        ->visible(fn (PlannedPost $record): bool => ! self::isImmutable($record) && $record->revisions->isNotEmpty())
                        ->action(function (PlannedPost $record, array $data, RequestPlannedPostRewrite $rewrite): void {
                            $user = auth()->user();
                            $revision = $record->revisions->firstWhere('id', (int) $data['revision_id']);

                            if ($user instanceof User && $revision instanceof PlannedPostRevision) {
                                $rewrite->restore($record, $revision, $user);
                                Notification::make()->title('Версия восстановлена')->success()->send();
                            }
                        }),
                    Action::make('reject')
                        ->label('Отклонить и заменить резервом')
                        ->icon('heroicon-m-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->schema([
                            Textarea::make('reason')->label('Причина')->required(),
                        ])
                        ->visible(fn (PlannedPost $record): bool => ! in_array($record->status, [PlannedPostStatus::Published, PlannedPostStatus::Cancelled], true))
                        ->action(function (PlannedPost $record, array $data, ApprovePlannedPost $approvePlannedPost): void {
                            $user = auth()->user();

                            if ($user instanceof User) {
                                $replacement = $approvePlannedPost->reject($record, $user, $data['reason']);
                                Notification::make()
                                    ->title($replacement->id === $record->id
                                        ? 'Публикация отклонена — резерв закончился'
                                        : 'Публикация заменена резервом')
                                    ->status($replacement->id === $record->id ? 'warning' : 'success')
                                    ->send();
                            }
                        }),
                ])->icon('heroicon-m-ellipsis-horizontal'),
            ])
            ->defaultSort('scheduled_at');
    }

    private static function statusLabel(mixed $state): string
    {
        return match ($state->value ?? $state) {
            'rewriting' => 'Рерайт',
            'final_review' => 'Нужно проверить',
            'blocked' => 'Есть риски',
            'approved' => 'Одобрено',
            'publishing' => 'Публикуется',
            'published' => 'Опубликовано',
            'failed' => 'Ошибка',
            'cancelled' => 'Отклонено',
            'needs_reschedule' => 'Нужно новое время',
            default => (string) ($state->value ?? $state),
        };
    }

    private static function isImmutable(PlannedPost $record): bool
    {
        return in_array($record->status, [
            PlannedPostStatus::Publishing,
            PlannedPostStatus::Published,
            PlannedPostStatus::Cancelled,
        ], true);
    }

    private static function riskDescription(PlannedPost $record): string
    {
        $risks = collect($record->risk_flags ?? [])
            ->map(fn (string $risk): string => match ($risk) {
                'source_conflict' => 'источники расходятся в деталях',
                'unreliable_content' => 'часть сведений требует ручной проверки',
                'possible_duplicate', 'duplicate_in_daily_plan' => 'возможен дубликат',
                'older_than_24h' => 'новость старше 24 часов',
                'ai_review_missing' => 'не получена итоговая проверка ИИ',
                default => Str::of($risk)->replace('_', ' ')->toString(),
            })
            ->implode('; ');

        return 'ИИ отметил: '.$risks.'. Если вы проверили текст, публикацию можно одобрить без комментария.';
    }
}
