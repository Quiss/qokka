<?php

namespace App\Filament\Pages;

use App\Models\Publication;
use App\Services\AdvertisingPostGenerationService;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Throwable;

/** @property-read Schema $form */
class AdvertisingPostGenerator extends Page
{
    protected static ?string $title = 'Сгенерировать рекламный пост';

    protected static ?string $navigationLabel = 'Сгенерировать рекламный пост';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|\UnitEnum|null $navigationGroup = 'Утилиты';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'advertising-post-generator';

    protected string $view = 'filament.pages.advertising-post-generator';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Данные для генерации')
                    ->description('Выберите свой канал и опишите площадку, в которой будет размещена реклама.')
                    ->schema([
                        Select::make('publication_id')
                            ->label('Мой канал')
                            ->helperText('Название, рекламное описание, тематика и тон будут подставлены из «Каналов публикаций».')
                            ->options(fn (): array => Publication::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->exists(Publication::class, 'id')
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('generated_content', null)),
                        Textarea::make('placement_channel_description')
                            ->label('Канал-площадка')
                            ->helperText('Опишите тематику, аудиторию и привычный стиль подачи канала, где выйдет реклама.')
                            ->placeholder('Например: канал о маркетинге для владельцев малого бизнеса. Автор пишет коротко, практично и с лёгкой иронией; аудитория скептически относится к прямой рекламе.')
                            ->rows(8)
                            ->autosize()
                            ->maxLength(10000)
                            ->trim()
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set): mixed => $set('generated_content', null)),
                    ]),
                Section::make('Результат')
                    ->description('Отредактируйте текст при необходимости и скопируйте все варианты одним действием.')
                    ->schema([
                        Textarea::make('generated_content')
                            ->label('Варианты рекламного поста')
                            ->rows(24)
                            ->autosize()
                            ->maxLength(30000),
                        Text::make('Скопировать весь результат')
                            ->icon(Heroicon::OutlinedClipboardDocument)
                            ->badge()
                            ->copyable()
                            ->copyableState(fn (Get $get): string => (string) $get('generated_content'))
                            ->copyMessage('Результат скопирован')
                            ->copyMessageDuration(1500),
                    ])
                    ->visible(fn (Get $get): bool => filled($get('generated_content'))),
            ])
            ->statePath('data');
    }

    public function generate(AdvertisingPostGenerationService $generationService): void
    {
        $data = $this->form->getState();
        $publication = Publication::query()
            ->with('destination')
            ->findOrFail($data['publication_id']);

        if (blank($publication->advertising_description)) {
            throw ValidationException::withMessages([
                'data.publication_id' => 'Сначала заполните «Описание канала для рекламы» в карточке выбранного канала.',
            ]);
        }

        $this->data['generated_content'] = null;

        try {
            $this->data['generated_content'] = $generationService->generate(
                publication: $publication,
                placementChannelDescription: $data['placement_channel_description'],
            );

            Notification::make()
                ->title('Рекламные посты сгенерированы')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Не удалось сгенерировать рекламные посты')
                ->body('Проверьте настройки OpenRouter и попробуйте ещё раз.')
                ->danger()
                ->send();
        }
    }
}
