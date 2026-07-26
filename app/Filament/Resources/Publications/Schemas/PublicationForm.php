<?php

namespace App\Filament\Resources\Publications\Schemas;

use App\DestinationPlatform;
use App\PublicationSignatureMode;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PublicationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Основное')
                    ->description('Куда публикуем, откуда берём новости и каким тоном переписываем.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Название канала публикаций')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug((string) $state))),
                        TextInput::make('slug')
                            ->label('Системное имя')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Select::make('source_group_id')
                            ->label('Группа источников')
                            ->relationship('sourceGroup', 'name')
                            ->preload()
                            ->searchable()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Канал активен')
                            ->default(true),
                        Textarea::make('selection_prompt')
                            ->label('Инструкция отбора новостей')
                            ->helperText('Опишите, какие инфоповоды подходят этому каналу. AI полностью исключит несоответствующие новости, даже если у них высокие охваты. Оставьте поле пустым для общего отбора.')
                            ->placeholder('Например: отбирай только новости, напрямую связанные с Санкт-Петербургом и жизнью его жителей. Общероссийские тренды включай, только если у них есть явный петербургский контекст.')
                            ->rows(6)
                            ->autosize()
                            ->trim()
                            ->columnSpanFull(),
                        Textarea::make('tone_prompt')
                            ->label('Редакционная инструкция для AI')
                            ->helperText('Главная настройка стиля этого канала. Здесь задаются тон, объём, число абзацев, ритм, начало и финал, цитаты, жирные акценты, эмодзи, юмор и правила для разных тем. Приложение отдельно контролирует только достоверность, безопасный Markdown и подпись.')
                            ->rows(12)
                            ->autosize()
                            ->trim()
                            ->required()
                            ->columnSpanFull(),
                        Placeholder::make('publisher_bot')
                            ->label('Бот публикации')
                            ->content(fn (): string => filled(config('services.telegram.bot_token'))
                                ? 'Используется единый бот из TELEGRAM_BOT_TOKEN. После сохранения проверьте его доступ в списке каналов публикаций.'
                                : 'TELEGRAM_BOT_TOKEN не настроен — публикация пока невозможна.')
                            ->columnSpanFull(),
                        Repeater::make('destinations')
                            ->label('Telegram-канал назначения')
                            ->relationship()
                            ->minItems(1)
                            ->maxItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Добавить Telegram-канал')
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('platform')
                                    ->default(DestinationPlatform::Telegram->value),
                                TextInput::make('name')
                                    ->label('Название')
                                    ->default('Telegram')
                                    ->required(),
                                TextInput::make('external_id')
                                    ->label('@username или chat_id')
                                    ->helperText('Добавьте бота из TELEGRAM_BOT_TOKEN администратором канала с правом публикации.')
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label('Публиковать')
                                    ->default(true),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Что произойдёт после сохранения')
                    ->description('1. Нажмите «Проверить бота и канал». 2. Нажмите «Собрать план сейчас». 3. Откройте раздел «Редакция», одобрите новости, запустите рерайт и подтвердите готовые посты.')
                    ->collapsed(),
                Section::make('Расписание')
                    ->description('Когда собирать подборку и в какое время выпускать материалы.')
                    ->columns(3)
                    ->schema([
                        TimePicker::make('planning_time')
                            ->label('Собирать план в')
                            ->required(),
                        TimePicker::make('publish_window_start')
                            ->label('Публиковать с')
                            ->required(),
                        TimePicker::make('publish_window_end')
                            ->label('Публиковать до')
                            ->required(),
                        TextInput::make('min_interval_minutes')
                            ->label('Минимальный интервал, мин')
                            ->required()
                            ->numeric()
                            ->minValue(30)
                            ->default(90),
                        TextInput::make('max_interval_minutes')
                            ->label('Максимальный интервал, мин')
                            ->required()
                            ->numeric()
                            ->gte('min_interval_minutes')
                            ->default(180),
                        TextInput::make('reserve_multiplier')
                            ->label('Запас кандидатов')
                            ->helperText('1.5 означает на 50% больше новостей, чем слотов.')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(3)
                            ->default(1.5),
                    ]),
                Section::make('Расширенные настройки')
                    ->description('Обычно эти значения можно не менять.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('language')
                            ->label('Язык')
                            ->required()
                            ->default('ru'),
                        TextInput::make('timezone')
                            ->label('Часовой пояс')
                            ->required()
                            ->default('Europe/Moscow'),
                        TagsInput::make('tone_examples')
                            ->label('Примеры желаемого текста')
                            ->helperText('AI использует их как ориентиры стиля и структуры, но не как шаблоны или источники фактов.')
                            ->columnSpanFull(),
                        TagsInput::make('forbidden_phrases')
                            ->label('Запрещённые фразы')
                            ->columnSpanFull(),
                        TagsInput::make('content_filters.blocked_phrases')
                            ->label('Стоп-фразы для источников')
                            ->columnSpanFull(),
                        TextInput::make('analysis_model')
                            ->label('Модель анализа'),
                        TextInput::make('rewrite_model')
                            ->label('Модель рерайта'),
                        TextInput::make('media_caption_limit')
                            ->label('Лимит подписи к медиа')
                            ->required()
                            ->numeric()
                            ->default(900),
                        Select::make('signature_mode')
                            ->label('Подпись поста')
                            ->options(collect(PublicationSignatureMode::cases())
                                ->mapWithKeys(fn (PublicationSignatureMode $mode): array => [$mode->value => $mode->label()])
                                ->all())
                            ->default(PublicationSignatureMode::None->value)
                            ->live()
                            ->required(),
                        TextInput::make('signature_label')
                            ->label('Текст ссылки')
                            ->placeholder('ПокаТренд')
                            ->visible(fn (Get $get): bool => $get('signature_mode') === PublicationSignatureMode::Link->value)
                            ->required(fn (Get $get): bool => $get('signature_mode') === PublicationSignatureMode::Link->value),
                        Placeholder::make('signature_preview')
                            ->label('Подпись, которую должен вернуть ИИ')
                            ->content(function (Get $get): string {
                                $mode = PublicationSignatureMode::tryFrom((string) $get('signature_mode'))
                                    ?? PublicationSignatureMode::None;

                                if ($mode === PublicationSignatureMode::None) {
                                    return 'Без подписи';
                                }

                                $destinations = $get('destinations');
                                $firstDestination = is_array($destinations) ? reset($destinations) : null;
                                $username = is_array($firstDestination)
                                    ? (string) ($firstDestination['external_id'] ?? '')
                                    : '';

                                if (! Str::startsWith($username, '@')) {
                                    return 'Для подписи нужен публичный @username канала.';
                                }

                                if ($mode === PublicationSignatureMode::Username) {
                                    return $username;
                                }

                                $label = filled($get('signature_label'))
                                    ? (string) $get('signature_label')
                                    : (string) $get('name');
                                $baseUrl = rtrim((string) config('services.telegram.messenger_base_url'), '/');

                                return "[{$label}]({$baseUrl}/".ltrim($username, '@').')';
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
