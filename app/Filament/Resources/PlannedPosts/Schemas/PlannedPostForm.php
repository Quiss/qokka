<?php

namespace App\Filament\Resources\PlannedPosts\Schemas;

use App\Models\PlannedPost;
use App\PlannedPostStatus;
use App\RiskFlagLabels;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PlannedPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('story')
                    ->content(fn (?PlannedPost $record): string => $record === null ? '—' : $record->storyCandidate->title),
                MarkdownEditor::make('text')
                    ->required()
                    ->toolbarButtons([
                        ['bold', 'italic', 'strike', 'link'],
                        ['blockquote'],
                        ['undo', 'redo'],
                    ])
                    ->columnSpanFull(),
                MarkdownEditor::make('original_ai_text')
                    ->disabled()
                    ->dehydrated(false)
                    ->toolbarButtons([])
                    ->columnSpanFull(),
                DateTimePicker::make('scheduled_at')
                    ->label('Время публикации')
                    ->timezone(fn (?PlannedPost $record): string => $record?->publicationTimezone() ?? (string) config('app.timezone'))
                    ->seconds(false)
                    ->helperText(fn (?PlannedPost $record): string => 'Часовой пояс: '.($record?->publicationTimezone() ?? config('app.timezone'))),
                Placeholder::make('status')
                    ->content(fn (?PlannedPost $record): string => $record === null ? PlannedPostStatus::Rewriting->value : $record->status->value),
                TagsInput::make('risk_flags')
                    ->label('Риски')
                    ->formatStateUsing(fn (?array $state): array => RiskFlagLabels::labels($state ?? []))
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('override_reason')
                    ->disabled()
                    ->dehydrated(false)
                    ->columnSpanFull(),
            ]);
    }
}
