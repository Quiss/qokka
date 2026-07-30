<?php

namespace App\Filament\Resources\Publications\Actions;

use App\Models\Publication;
use App\Services\TonGenerationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Arr;
use Throwable;

class PublicationToneGenerationAction
{
    public static function make(): Action
    {
        return Action::make('generateTone')
            ->label('Сгенерировать тон')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->modalHeading('Сгенерировать тон канала')
            ->modalDescription('Опишите канал, получите редакционную инструкцию и проверьте её перед применением.')
            ->modalWidth(Width::FiveExtraLarge)
            ->fillForm(function (Get $schemaGet): array {
                $name = (string) $schemaGet('name');
                $selectionPrompt = (string) $schemaGet('selection_prompt');
                $topic = filled($selectionPrompt)
                    ? trim($name)."\n\nТематика и критерии отбора:\n".trim($selectionPrompt)
                    : trim($name);
                $toneExamples = $schemaGet('tone_examples');

                return [
                    'topic' => $topic,
                    'audience' => null,
                    'cliche_examples' => null,
                    'desired_tone_example' => is_array($toneExamples)
                        ? Arr::first($toneExamples, fn (mixed $example): bool => is_string($example) && filled($example))
                        : null,
                    'generated_tone' => null,
                    'model' => $schemaGet('rewrite_model'),
                ];
            })
            ->steps([
                Step::make('Контекст')
                    ->description('Расскажите, для кого и о чём пишет канал.')
                    ->schema([
                        Textarea::make('topic')
                            ->label('Тема канала')
                            ->rows(4)
                            ->maxLength(2000)
                            ->trim()
                            ->required(),
                        Textarea::make('audience')
                            ->label('Аудитория')
                            ->rows(4)
                            ->maxLength(2000)
                            ->trim()
                            ->required(),
                        Textarea::make('cliche_examples')
                            ->label('Примеры шаблонных постов')
                            ->helperText('Необязательно. Добавьте тексты или повторяющиеся фразы, от которых нужно избавиться.')
                            ->rows(7)
                            ->maxLength(10000)
                            ->trim(),
                        Textarea::make('desired_tone_example')
                            ->label('Пример желаемого тона')
                            ->helperText('Необязательно. Это ориентир по голосу, а не текст для копирования.')
                            ->rows(7)
                            ->maxLength(10000)
                            ->trim(),
                        Hidden::make('model'),
                    ])
                    ->afterValidation(function (
                        Get $get,
                        Set $set,
                        TonGenerationService $tonGenerationService,
                        ?Publication $record,
                    ): void {
                        try {
                            $set('generated_tone', $tonGenerationService->generate(
                                topic: (string) $get('topic'),
                                audience: (string) $get('audience'),
                                clicheExamples: $get('cliche_examples'),
                                desiredToneExample: $get('desired_tone_example'),
                                publication: $record,
                                model: $get('model'),
                            ));
                        } catch (Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Не удалось сгенерировать тон')
                                ->body('Проверьте настройки OpenRouter и попробуйте ещё раз.')
                                ->danger()
                                ->send();

                            throw new Halt;
                        }
                    }),
                Step::make('Предпросмотр')
                    ->description('Отредактируйте результат перед применением.')
                    ->schema([
                        Textarea::make('generated_tone')
                            ->label('Редакционная инструкция')
                            ->helperText('Этот текст будет перенесён в основную форму только после нажатия «Применить».')
                            ->rows(18)
                            ->maxLength(20000)
                            ->autosize()
                            ->trim()
                            ->required(),
                    ]),
            ])
            ->modifyWizardUsing(
                fn (Wizard $wizard): Wizard => $wizard->nextAction(
                    fn (Action $action): Action => $action->label('Сгенерировать'),
                ),
            )
            ->modalSubmitActionLabel('Применить')
            ->modalCancelActionLabel('Отмена')
            ->action(function (array $data, Set $schemaSet): void {
                $schemaSet('tone_prompt', $data['generated_tone']);

                Notification::make()
                    ->title('Тон добавлен в редакционную инструкцию')
                    ->success()
                    ->send();
            });
    }
}
