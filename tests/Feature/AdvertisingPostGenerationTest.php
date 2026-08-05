<?php

namespace Tests\Feature;

use App\AiOperation;
use App\Filament\Pages\AdvertisingPostGenerator;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Models\AiRun;
use App\Models\Destination;
use App\Models\Publication;
use App\Models\User;
use App\Services\AdvertisingPostGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AdvertisingPostGenerationTest extends TestCase
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

    public function test_service_generates_advertising_posts_from_publication_and_placement_data(): void
    {
        $publication = Publication::factory()->create([
            'name' => 'Практичный AI',
            'advertising_description' => 'Практические AI-сценарии для предпринимателей.',
            'selection_prompt' => 'Только прикладные инструменты и кейсы.',
            'tone_prompt' => 'Пиши коротко и уверенно.',
            'tone_examples' => ['Автоматизация без лишней теории.'],
            'forbidden_phrases' => ['Уникальная возможность'],
            'rewrite_model' => 'publication-rewrite-model',
        ]);
        Destination::factory()->for($publication)->create([
            'external_id' => '@practical_ai',
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "  **Вариант 1 — Любопытство**\nТекст с [LINK]  "]],
                ],
                'usage' => ['cost' => 0.0025, 'total_tokens' => 950],
            ]),
        ]);

        $result = app(AdvertisingPostGenerationService::class)->generate(
            publication: $publication,
            placementChannelDescription: 'Маркетинговый канал для владельцев малого бизнеса с ироничной подачей.',
        );

        $this->assertSame("**Вариант 1 — Любопытство**\nТекст с [LINK]", $result);
        Http::assertSent(function (Request $request): bool {
            $systemPrompt = (string) data_get($request->data(), 'messages.0.content');
            $userPrompt = (string) data_get($request->data(), 'messages.1.content');

            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && data_get($request->data(), 'model') === 'publication-rewrite-model'
                && data_get($request->data(), 'temperature') === 0.7
                && data_get($request->data(), 'messages.0.role') === 'system'
                && data_get($request->data(), 'messages.1.role') === 'user'
                && str_contains($systemPrompt, 'Выдай 3 варианта поста')
                && ! str_contains($systemPrompt, 'Пара рекомендаций по интеграции')
                && str_contains($userPrompt, 'Практичный AI')
                && str_contains($userPrompt, 'Практические AI-сценарии для предпринимателей.')
                && str_contains($userPrompt, 'Пиши коротко и уверенно.')
                && str_contains($userPrompt, '@practical_ai')
                && str_contains($userPrompt, 'Маркетинговый канал для владельцев малого бизнеса');
        });

        $run = AiRun::query()->sole();

        $this->assertSame(AiOperation::GenerateAdvertisingPost, $run->operation);
        $this->assertSame(Publication::class, $run->subject_type);
        $this->assertSame($publication->id, $run->subject_id);
        $this->assertSame('publication-rewrite-model', $run->model);
        $this->assertSame('v1', $run->prompt_version);
        $this->assertSame('completed', $run->status);
        $this->assertSame('0.002500', $run->cost_usd);
        $this->assertNotNull($run->response_payload);
    }

    public function test_service_uses_the_default_rewrite_model(): void
    {
        $publication = Publication::factory()->create(['rewrite_model' => null]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Готовые варианты']],
                ],
            ]),
        ]);

        app(AdvertisingPostGenerationService::class)->generate(
            publication: $publication,
            placementChannelDescription: 'Описание площадки',
        );

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'model') === 'default-rewrite-model');
    }

    public function test_service_records_a_missing_api_key_as_a_failed_run(): void
    {
        config()->set('services.openrouter.key');
        $publication = Publication::factory()->create();

        try {
            app(AdvertisingPostGenerationService::class)->generate(
                publication: $publication,
                placementChannelDescription: 'Описание площадки',
            );

            $this->fail('The generation should fail without an API key.');
        } catch (RuntimeException) {
            $run = AiRun::query()->sole();

            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertNotEmpty($run->error);
        }
    }

    public function test_service_records_a_failed_openrouter_request(): void
    {
        $publication = Publication::factory()->create();
        Http::fake([
            'https://openrouter.test/*' => Http::response(['error' => 'unavailable'], 503),
        ]);

        try {
            app(AdvertisingPostGenerationService::class)->generate(
                publication: $publication,
                placementChannelDescription: 'Описание площадки',
            );

            $this->fail('The OpenRouter request should fail.');
        } catch (RequestException) {
            $run = AiRun::query()->sole();

            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->completed_at);
            $this->assertNotEmpty($run->error);
        }
    }

    public function test_service_records_an_invalid_response_as_a_failed_run(): void
    {
        $publication = Publication::factory()->create();
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '']],
                ],
            ]),
        ]);

        try {
            app(AdvertisingPostGenerationService::class)->generate(
                publication: $publication,
                placementChannelDescription: 'Описание площадки',
            );

            $this->fail('The invalid response should fail.');
        } catch (RuntimeException) {
            $run = AiRun::query()->sole();

            $this->assertSame('failed', $run->status);
            $this->assertNotNull($run->response_payload);
            $this->assertNotEmpty($run->error);
        }
    }

    public function test_admin_page_generates_an_editable_result(): void
    {
        $this->actingAs(User::factory()->create());
        $publication = Publication::factory()->create([
            'advertising_description' => 'Описание своего канала',
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Три готовых варианта']],
                ],
            ]),
        ]);

        Livewire::test(AdvertisingPostGenerator::class)
            ->assertOk()
            ->fillForm([
                'publication_id' => $publication->id,
                'placement_channel_description' => 'Описание канала-площадки',
            ])
            ->call('generate')
            ->assertHasNoFormErrors()
            ->assertSchemaStateSet([
                'generated_content' => 'Три готовых варианта',
            ])
            ->assertNotified('Рекламные посты сгенерированы');
    }

    public function test_admin_page_validates_required_input(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(AdvertisingPostGenerator::class)
            ->fillForm([
                'publication_id' => null,
                'placement_channel_description' => null,
            ])
            ->call('generate')
            ->assertHasFormErrors([
                'publication_id' => 'required',
                'placement_channel_description' => 'required',
            ]);
    }

    public function test_admin_page_requires_a_saved_advertising_description(): void
    {
        $this->actingAs(User::factory()->create());
        $publication = Publication::factory()->create([
            'advertising_description' => null,
        ]);

        Livewire::test(AdvertisingPostGenerator::class)
            ->fillForm([
                'publication_id' => $publication->id,
                'placement_channel_description' => 'Описание канала-площадки',
            ])
            ->call('generate')
            ->assertHasErrors(['data.publication_id']);

        Http::assertNothingSent();
    }

    public function test_admin_page_shows_a_danger_notification_when_generation_fails(): void
    {
        $this->actingAs(User::factory()->create());
        config()->set('services.openrouter.key');
        $publication = Publication::factory()->create();

        Livewire::test(AdvertisingPostGenerator::class)
            ->fillForm([
                'publication_id' => $publication->id,
                'placement_channel_description' => 'Описание канала-площадки',
            ])
            ->call('generate')
            ->assertSchemaStateSet([
                'generated_content' => null,
            ])
            ->assertNotified('Не удалось сгенерировать рекламные посты');

        $this->assertSame('failed', AiRun::query()->sole()->status);
    }

    public function test_advertising_description_can_be_saved_on_the_publication_form(): void
    {
        $this->actingAs(User::factory()->create());
        $publication = Publication::factory()->create();
        Destination::factory()->for($publication)->create();

        Livewire::test(EditPublication::class, ['record' => $publication->getRouteKey()])
            ->assertFormFieldExists('advertising_description')
            ->fillForm([
                'advertising_description' => 'Постоянный рекламный бриф канала.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'Постоянный рекламный бриф канала.',
            $publication->refresh()->advertising_description,
        );
    }
}
