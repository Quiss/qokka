<?php

namespace Tests\Feature;

use App\AiOperation;
use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Models\AiRun;
use App\Models\Publication;
use App\Models\User;
use App\Services\TonGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TonGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
            'services.openrouter.rewrite_model' => 'default-rewrite-model',
        ]);

        Http::preventStrayRequests();
    }

    public function test_service_generates_tone_from_embedded_prompt_and_records_run(): void
    {
        $publication = Publication::factory()->create([
            'rewrite_model' => 'publication-rewrite-model',
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "  ГОЛОС\nЖивой и внимательный.  "]],
                ],
                'usage' => ['cost' => 0.0012, 'total_tokens' => 420],
            ]),
        ]);

        $result = app(TonGenerationService::class)->generate(
            topic: 'Городская культура Петербурга',
            audience: 'Горожане 25–45 лет',
            clicheExamples: 'Уникальная возможность. Не оставит равнодушным.',
            desiredToneExample: 'Рассказываем спокойно, но с характером.',
            publication: $publication,
        );

        $this->assertSame("ГОЛОС\nЖивой и внимательный.", $result);
        Http::assertSent(function (Request $request): bool {
            $prompt = (string) data_get($request->data(), 'messages.0.content');

            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && data_get($request->data(), 'model') === 'publication-rewrite-model'
                && data_get($request->data(), 'temperature') === 0.7
                && str_contains($prompt, 'Тема канала: Городская культура Петербурга')
                && str_contains($prompt, 'Аудиория: Горожане 25–45 лет')
                && str_contains($prompt, 'Примеры постов-клише (если есть): Уникальная возможность.')
                && str_contains($prompt, 'Пример желаемого тона (если есть): Рассказываем спокойно')
                && ! str_contains($prompt, '[тема]')
                && ! str_contains($prompt, '[аудитория]')
                && ! str_contains($prompt, '[вставить или "нет"]');
        });

        $run = AiRun::query()->sole();

        $this->assertSame(AiOperation::GenerateTone, $run->operation);
        $this->assertSame(Publication::class, $run->subject_type);
        $this->assertSame($publication->id, $run->subject_id);
        $this->assertSame('publication-rewrite-model', $run->model);
        $this->assertSame('v1', $run->prompt_version);
        $this->assertSame('completed', $run->status);
        $this->assertSame('0.001200', $run->cost_usd);
    }

    public function test_service_uses_defaults_for_optional_context_and_model(): void
    {
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Готовая инструкция']],
                ],
            ]),
        ]);

        app(TonGenerationService::class)->generate(
            topic: 'Технологии',
            audience: 'Разработчики',
        );

        Http::assertSent(function (Request $request): bool {
            $prompt = (string) data_get($request->data(), 'messages.0.content');

            return data_get($request->data(), 'model') === 'default-rewrite-model'
                && str_contains($prompt, 'Примеры постов-клише (если есть): нет')
                && str_contains($prompt, 'Пример желаемого тона (если есть): нет');
        });

        $run = AiRun::query()->sole();

        $this->assertNull($run->subject_type);
        $this->assertNull($run->subject_id);
    }

    public function test_service_records_a_failed_openrouter_request(): void
    {
        Http::fake([
            'https://openrouter.test/*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        try {
            app(TonGenerationService::class)->generate(
                topic: 'Технологии',
                audience: 'Разработчики',
            );

            $this->fail('The OpenRouter request should fail.');
        } catch (RequestException) {
            $run = AiRun::query()->sole();

            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertNotEmpty($run->error);
        }
    }

    public function test_create_form_generates_a_preview_before_applying_the_tone(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Сгенерированная инструкция']],
                ],
            ]),
        ]);

        Livewire::test(CreatePublication::class)
            ->fillForm([
                'name' => 'Новости Петербурга',
                'selection_prompt' => 'Только городские события.',
                'tone_prompt' => 'Старая инструкция',
                'tone_examples' => ['Живой пример поста'],
                'rewrite_model' => 'form-rewrite-model',
            ])
            ->assertFormComponentActionVisible('tone_prompt', 'generateTone')
            ->mountFormComponentAction('tone_prompt', 'generateTone')
            ->assertFormComponentActionDataSet(function (array $state): bool {
                return $state['topic'] === "Новости Петербурга\n\nТематика и критерии отбора:\nТолько городские события."
                    && $state['desired_tone_example'] === 'Живой пример поста'
                    && $state['model'] === 'form-rewrite-model';
            })
            ->setFormComponentActionData([
                'topic' => 'Городские новости',
                'audience' => 'Жители Петербурга',
                'cliche_examples' => null,
                'desired_tone_example' => 'Живой пример поста',
                'model' => 'form-rewrite-model',
            ])
            ->goToNextWizardStep()
            ->assertWizardCurrentStep(2)
            ->assertFormComponentActionDataSet([
                'generated_tone' => 'Сгенерированная инструкция',
            ])
            ->assertSchemaStateSet([
                'tone_prompt' => 'Старая инструкция',
            ], 'form')
            ->setFormComponentActionData([
                'generated_tone' => 'Отредактированная инструкция',
            ])
            ->callMountedFormComponentAction()
            ->assertSchemaStateSet([
                'tone_prompt' => 'Отредактированная инструкция',
            ], 'form')
            ->assertNotified('Тон добавлен в редакционную инструкцию');

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'model') === 'form-rewrite-model');
    }

    public function test_tone_generation_action_is_available_when_editing(): void
    {
        $this->actingAs(User::factory()->create());
        $publication = Publication::factory()->create();

        Livewire::test(EditPublication::class, ['record' => $publication->getRouteKey()])
            ->assertFormComponentActionVisible('tone_prompt', 'generateTone');
    }
}
