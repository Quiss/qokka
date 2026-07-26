<?php

namespace App\Filament\Resources\SourceChannels\RelationManagers;

use App\Actions\QueueMediaAssetDownloadRetries;
use App\Models\MediaAsset;
use App\Models\SourcePost;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PostsRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    protected static ?string $title = 'Посты и статистика за последние 24 часа';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('text')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['sourceChannel', 'mediaAssets'])
                ->where('posted_at', '>=', now()->subDay())
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->latest('posted_at'))
            ->columns([
                Split::make([
                    ImageColumn::make('media_preview')
                        ->label('Медиа')
                        ->state(fn (SourcePost $record): ?string => $record->mediaAssets
                            ->map(fn (MediaAsset $asset): ?string => $asset->previewUrl())
                            ->first(fn (?string $url): bool => filled($url)))
                        ->defaultImageUrl(self::mediaPlaceholder())
                        ->alt(fn (SourcePost $record): string => 'Медиа исходного поста от '.$record->posted_at->format('d.m.Y H:i'))
                        ->imageSize(72)
                        ->square()
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('text')
                            ->label('Пост')
                            ->weight(FontWeight::Medium)
                            ->limit(180)
                            ->wrap()
                            ->placeholder('Медиа без подписи')
                            ->searchable(),
                        TextColumn::make('media_count')
                            ->label('Медиафайлы')
                            ->state(fn (SourcePost $record): string => $record->mediaAssets->count().' медиа')
                            ->icon('heroicon-m-photo')
                            ->color('gray'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('posted_at')
                            ->label('Опубликован')
                            ->dateTime('d.m, H:i')
                            ->icon('heroicon-m-clock')
                            ->sortable(),
                        TextColumn::make('engagement')
                            ->label('Охват')
                            ->state(fn (SourcePost $record): string => number_format($record->views, 0, ',', ' ').' просмотров')
                            ->icon('heroicon-m-eye'),
                        TextColumn::make('reactions')
                            ->label('Реакции')
                            ->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', ' ').' реакций')
                            ->icon('heroicon-m-heart'),
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
                    ->modalHeading(fn (SourcePost $record): string => 'Пост от '.$record->posted_at->format('d.m.Y H:i'))
                    ->schema([
                        View::make('filament.source-channels.source-post-details'),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                Action::make('telegram')
                    ->label('В Telegram')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (SourcePost $record): ?string => $record->source_url)
                    ->openUrlInNewTab()
                    ->visible(fn (SourcePost $record): bool => filled($record->source_url)),
                Action::make('retry_media_download')
                    ->label('Повторить загрузку медиа')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Исходное сообщение будет заново получено из Telegram, после чего загрузка медиа повторится.')
                    ->visible(fn (SourcePost $record): bool => $record->mediaAssets->contains(
                        fn (MediaAsset $asset): bool => blank($asset->path) && $asset->failed_at !== null,
                    ))
                    ->action(function (
                        SourcePost $record,
                        QueueMediaAssetDownloadRetries $queueMediaAssetDownloadRetries,
                    ): void {
                        $queued = $queueMediaAssetDownloadRetries->handle(
                            $record->mediaAssets->filter(
                                fn (MediaAsset $asset): bool => blank($asset->path)
                                    && $asset->failed_at !== null,
                            ),
                        );

                        Notification::make()
                            ->title($queued > 0
                                ? 'Повторная загрузка медиа поставлена в очередь'
                                : 'Нет медиа для повторной загрузки')
                            ->status($queued > 0 ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }

    private static function mediaPlaceholder(): string
    {
        return 'data:image/svg+xml,'.rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96"><rect width="96" height="96" fill="#e4e4e7"/><path d="M28 31h40v34H28z" fill="none" stroke="#71717a" stroke-width="3"/><path d="m32 59 10-11 8 8 6-7 8 10" fill="none" stroke="#71717a" stroke-width="3"/></svg>',
        );
    }
}
