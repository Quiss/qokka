<?php

namespace Tests\Feature;

use App\Actions\GenerateCandidateBatch;
use App\AiOperation;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\MediaType;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PublicationSignatureMode;
use App\Services\ContentPlanSlotGenerator;
use App\Services\OpenRouterContentIntelligence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_slot_generation_is_repeatable_and_within_configured_window(): void
    {
        $publication = Publication::factory()->create([
            'publish_window_start' => '09:00',
            'publish_window_end' => '23:00',
            'min_interval_minutes' => 90,
            'max_interval_minutes' => 180,
        ]);
        $generator = app(ContentPlanSlotGenerator::class);
        $date = CarbonImmutable::parse('2026-07-21', 'Europe/Moscow');

        $first = $generator->generate($publication, $date);
        $second = $generator->generate($publication, $date);

        $this->assertSame($first, $second);
        $this->assertSame('09:00', CarbonImmutable::parse($first[0])->setTimezone('Europe/Moscow')->format('H:i'));

        foreach (array_slice($first, 1) as $index => $slot) {
            $minutes = CarbonImmutable::parse($first[$index])->diffInMinutes(CarbonImmutable::parse($slot));
            $this->assertGreaterThanOrEqual(90, $minutes);
            $this->assertLessThanOrEqual(180, $minutes);
        }
    }

    public function test_fewer_posts_are_spread_across_the_full_publication_window(): void
    {
        $slots = collect(range(9, 21, 2))
            ->map(fn (int $hour): string => CarbonImmutable::parse(
                "2026-07-27 {$hour}:00:00",
                'Europe/Moscow',
            )->utc()->toIso8601String())
            ->all();
        $generator = app(ContentPlanSlotGenerator::class);

        $spreadSlots = $generator->spreadAcrossWindow($slots, 4);
        $singleSlot = $generator->spreadAcrossWindow($slots, 1);

        $this->assertSame(
            ['09:00', '13:00', '17:00', '21:00'],
            array_map(
                fn (string $slot): string => CarbonImmutable::parse($slot)
                    ->setTimezone('Europe/Moscow')
                    ->format('H:i'),
                $spreadSlots,
            ),
        );
        $this->assertSame(
            ['15:00'],
            array_map(
                fn (string $slot): string => CarbonImmutable::parse($slot)
                    ->setTimezone('Europe/Moscow')
                    ->format('H:i'),
                $singleSlot,
            ),
        );
        $this->assertSame($slots, $generator->spreadAcrossWindow($slots, count($slots) + 1));
        $this->assertSame([], $generator->spreadAcrossWindow($slots, 0));
    }

    public function test_open_router_ranking_serializes_immutable_posted_at_and_enum_media(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $channel = Source::factory()->create();
        $plan = ContentPlan::factory()->create();
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => CarbonImmutable::now()->subHour(),
        ]);
        $sourcePost->mediaAssets()->create([
            'external_id' => 'photo-1',
            'type' => MediaType::Photo,
            'disk' => 'local',
            'path' => 'telegram/photo-1.jpg',
            'mime_type' => 'image/jpeg',
            'sort_order' => 0,
        ]);
        $sourcePost = $sourcePost->fresh(['source', 'mediaAssets']);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['clusters' => []], JSON_THROW_ON_ERROR),
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $sourcePost->posted_at);

        $result = app(OpenRouterContentIntelligence::class)
            ->rankAndCluster($plan, $sourcePost->newCollection([$sourcePost]));

        $this->assertSame([], $result['clusters']);
        Http::assertSent(function (Request $request) use ($plan): bool {
            $prompt = (string) data_get($request->data(), 'messages.1.content.0.text');

            return str_contains($prompt, '"media":["photo"]')
                && str_contains($prompt, '"plan_date":"'.$plan->plan_date->toDateString().'"')
                && str_contains($prompt, '"publication_timezone":"Europe\/Moscow"')
                && str_contains($prompt, 'Не включай прогноз погоды')
                && str_contains($prompt, 'относительно posted_at источника')
                && ! str_contains($prompt, '"selection_prompt"')
                && ! str_contains($prompt, 'обязательный тематический фильтр');
        });
        $this->assertSame('v6', AiRun::query()->sole()->prompt_version);
    }

    public function test_open_router_treats_json_collection_as_atomic_content_without_metric_penalty(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $source = Source::factory()->jsonCollection()->create(['title' => 'Qokka']);
        $plan = ContentPlan::factory()->create();
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $source->id,
            'metrics' => ['available' => false],
            'metadata' => [
                'content_kind' => 'collection',
                'collection' => [
                    'collection_id' => 'collection-1',
                    'title' => 'Детективы',
                    'items' => [[
                        'work_id' => 'work-1',
                        'title' => 'Первый фильм',
                        'year' => 2025,
                        'description' => 'Описание.',
                        'rating' => ['value' => 8.5, 'scale' => 10, 'source' => 'kinopoisk'],
                    ]],
                ],
            ],
        ])->fresh(['source', 'mediaAssets']);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['clusters' => []], JSON_THROW_ON_ERROR),
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        app(OpenRouterContentIntelligence::class)
            ->rankAndCluster($plan, $sourcePost->newCollection([$sourcePost]));

        Http::assertSent(function (Request $request): bool {
            $prompt = (string) data_get($request->data(), 'messages.1.content.0.text');

            return str_contains($prompt, 'content_kind=collection')
                && str_contains($prompt, '"content_kind":"collection"')
                && str_contains($prompt, '"metrics_available":false')
                && str_contains($prompt, '"preliminary_score":null')
                && str_contains($prompt, '"title":"Первый фильм"');
        });
    }

    public function test_open_router_validates_draft_cluster_sources_before_returning_them(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $channel = Source::factory()->create();
        $plan = ContentPlan::factory()->create(['candidate_target' => 2]);
        $lightning = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Молния ударила в ракету во время запуска на космодроме.',
        ]);
        $nettle = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Во ВкусВилл появился набор для битья крапивы.',
        ]);
        $posts = SourcePost::query()
            ->with(['source', 'mediaAssets'])
            ->whereKey([$lightning->id, $nettle->id])
            ->get();
        Http::fake([
            'https://openrouter.test/*' => Http::sequence()
                ->push($this->openRouterResponse([[
                    'source_post_ids' => [$nettle->id, $lightning->id],
                    'title' => 'Молния ударила в ракету',
                    'summary' => 'Разряд прошёл через ракету.',
                    'score' => 90,
                    'score_breakdown' => [],
                    'selection_reason' => 'Важное событие.',
                    'risk_flags' => [],
                    'source_conflicts' => [],
                ]]))
                ->push($this->openRouterResponse([[
                    'source_post_ids' => [$lightning->id],
                    'title' => 'Молния ударила в ракету',
                    'summary' => 'Разряд прошёл через ракету во время запуска.',
                    'score' => 90,
                    'score_breakdown' => [],
                    'selection_reason' => 'Источник подтверждает событие.',
                    'risk_flags' => [],
                    'source_conflicts' => [],
                ]])),
        ]);

        $result = app(OpenRouterContentIntelligence::class)->rankAndCluster($plan, $posts);

        $this->assertSame([$lightning->id], $result['clusters'][0]['source_post_ids']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => data_get(
            $request->data(),
            'response_format.json_schema.name',
        ) === 'validate_clusters' && str_contains(
            (string) data_get($request->data(), 'messages.1.content.0.text'),
            'набор для битья крапивы',
        ));
    }

    public function test_open_router_applies_channel_selection_prompt_to_ranking_and_validation(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $selectionPrompt = 'Отбирай только новости, напрямую связанные с Санкт-Петербургом.';
        $publication = Publication::factory()->create(['selection_prompt' => $selectionPrompt]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'candidate_target' => 1,
        ]);
        $channel = Source::factory()->create();
        $sourcePost = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'В Санкт-Петербурге открыли новую станцию метро.',
        ])->load(['source', 'mediaAssets']);
        $cluster = [
            'source_post_ids' => [$sourcePost->id],
            'title' => 'В Петербурге открыли станцию метро',
            'summary' => 'Новая станция начала принимать пассажиров.',
            'score' => 90,
            'score_breakdown' => [],
            'selection_reason' => 'Событие напрямую связано с Санкт-Петербургом.',
            'risk_flags' => [],
            'source_conflicts' => [],
        ];
        Http::fake([
            'https://openrouter.test/*' => Http::sequence()
                ->push($this->openRouterResponse([$cluster]))
                ->push($this->openRouterResponse([$cluster])),
        ]);

        $result = app(OpenRouterContentIntelligence::class)
            ->rankAndCluster($plan, $sourcePost->newCollection([$sourcePost]));

        $this->assertSame([$sourcePost->id], $result['clusters'][0]['source_post_ids']);
        $this->assertSame($selectionPrompt, $publication->fresh()->selection_prompt);
        Http::assertSentCount(2);

        foreach ([AiOperation::RankAndCluster, AiOperation::ValidateClusters] as $operation) {
            Http::assertSent(function (Request $request) use ($operation, $selectionPrompt): bool {
                $prompt = (string) data_get($request->data(), 'messages.1.content.0.text');

                return data_get($request->data(), 'response_format.json_schema.name') === $operation->value
                    && str_contains($prompt, $selectionPrompt)
                    && str_contains($prompt, 'обязательный тематический фильтр')
                    && str_contains($prompt, 'верни меньше кластеров или пустой список')
                    && str_contains($prompt, 'selection_reason');
            });
        }

        $this->assertSame(
            ['v6', 'v6'],
            AiRun::query()->oldest('id')->pluck('prompt_version')->all(),
        );
    }

    public function test_strict_selection_filter_can_generate_an_empty_plan(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create([
            'source_group_id' => $group->id,
            'selection_prompt' => 'Отбирай только новости Санкт-Петербурга.',
        ]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Generating,
        ]);
        SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Общероссийская новость без связи с Петербургом.',
            'posted_at' => now()->subHour(),
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response(
                $this->openRouterResponse([]),
            ),
        ]);

        app(GenerateCandidateBatch::class)->handle($plan);

        $plan->refresh();
        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->status);
        $this->assertNotNull($plan->generated_at);
        $this->assertSame(0, $plan->storyCandidates()->count());
        Http::assertSentCount(1);
    }

    public function test_rewrite_uses_channel_editorial_instruction_and_retries_an_invalid_signature_once(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
            'services.telegram.messenger_base_url' => 'https://t.me',
        ]);
        $publication = Publication::factory()->create([
            'signature_mode' => PublicationSignatureMode::Link,
            'signature_label' => 'ПокаТренд',
            'tone_prompt' => 'Пиши ровно одним абзацем, без эмодзи и закончи самым сильным фактом.',
            'tone_examples' => ['Коротко и живо'],
        ]);
        Destination::factory()->create([
            'publication_id' => $publication->id,
            'external_id' => '@PokaTrend',
        ]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-27',
        ]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $previousPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => $plan->plan_date->subDay(),
        ]);
        $previousCandidate = StoryCandidate::factory()->create(['content_plan_id' => $previousPlan->id]);
        PlannedPost::factory()->create([
            'content_plan_id' => $previousPlan->id,
            'story_candidate_id' => $previousCandidate->id,
            'text' => 'Недавний пост с повторяющейся структурой.',
        ]);
        $sourcePost = SourcePost::factory()->create([
            'text' => 'Глава компании сказал: «Мы представили новый продукт».',
            'posted_at' => CarbonImmutable::parse('2026-07-26 18:00:00', 'Europe/Moscow')->utc(),
        ]);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => CarbonImmutable::parse('2026-07-27 12:00:00', 'Europe/Moscow')->utc(),
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::sequence()
                ->push($this->rewriteResponse('**Новый продукт уже представлен**'))
                ->push($this->rewriteResponse("**Новый продукт уже представлен**\n\n[ПокаТренд](https://t.me/PokaTrend)")),
        ]);

        $result = app(OpenRouterContentIntelligence::class)->rewrite($plannedPost);

        $this->assertSame(
            "**Новый продукт уже представлен**\n\n[ПокаТренд](https://t.me/PokaTrend)",
            $result['text'],
        );
        Http::assertSentCount(2);
        $systemPrompt = (string) data_get(Http::recorded()[0][0]->data(), 'messages.0.content');
        $prompt = (string) data_get(Http::recorded()[0][0]->data(), 'messages.1.content.0.text');
        $correctionSystemPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'messages.0.content');
        $this->assertStringContainsString('В готовом посте используй только абсолютные календарные даты', $systemPrompt);
        $this->assertStringContainsString('Не используй «сегодня», «завтра», «послезавтра», «вчера»', $systemPrompt);
        $this->assertStringContainsString('++подчеркнутый++', $systemPrompt);
        $this->assertStringContainsString('||спойлер||', $systemPrompt);
        $this->assertStringContainsString('> [!EXPANDABLE]', $systemPrompt);
        $this->assertStringContainsString('Исправь только подпись готового поста', $correctionSystemPrompt);
        $this->assertStringContainsString('++подчеркнутый++', $correctionSystemPrompt);
        $this->assertStringNotContainsString('Разрешена обычная разметка Markdown', $prompt);
        $this->assertStringContainsString('единственный источник постоянных требований к тону', $prompt);
        $this->assertStringContainsString('Пиши ровно одним абзацем, без эмодзи и закончи самым сильным фактом.', $prompt);
        $this->assertStringContainsString('Недавний пост с повторяющейся структурой.', $prompt);
        $this->assertStringContainsString('[ПокаТренд](https://t.me/PokaTrend)', $prompt);
        $this->assertStringContainsString('"scheduled_at":"2026-07-27T12:00:00+03:00"', $prompt);
        $this->assertStringContainsString('"local_date":"2026-07-27"', $prompt);
        $this->assertStringContainsString('"posted_at_local":"2026-07-26T18:00:00+03:00"', $prompt);
        $this->assertStringContainsString('"relative_dates":{"вчера":"2026-07-25","сегодня":"2026-07-26","завтра":"2026-07-27","послезавтра":"2026-07-28"}', $prompt);
        $this->assertStringContainsString('stale_at_publication', $prompt);
        $this->assertStringContainsString('используй только готовое соответствие relative_dates', $prompt);
        $this->assertStringNotContainsString('легкий инфоповод может занять 1–2 коротких абзаца', $prompt);
        $this->assertStringNotContainsString('используй от 0 до 2 жирных акцентов', $prompt);
        $this->assertSame(['v7', 'v7'], AiRun::query()->orderBy('id')->pluck('prompt_version')->all());
    }

    public function test_plan_review_receives_source_and_publication_times_for_freshness_checks(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $publication = Publication::factory()->create(['timezone' => 'Europe/Moscow']);
        $slot = CarbonImmutable::parse('2026-07-27 12:00:00', 'Europe/Moscow');
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-27',
            'slot_schedule' => [$slot->utc()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create([
            'text' => 'Погода 26 июля: в Петербурге ожидается дождь.',
            'posted_at' => CarbonImmutable::parse('2026-07-26 18:00:00', 'Europe/Moscow')->utc(),
        ]);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => $slot->utc(),
            'text' => 'В Петербурге ожидается дождь.',
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'items' => [[
                                'planned_post_id' => $plannedPost->id,
                                'risk_flags' => ['stale_at_publication'],
                                'reason' => 'Прогноз относится к предыдущему дню.',
                            ]],
                            'duplicate_groups' => [],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        $result = app(OpenRouterContentIntelligence::class)->reviewPlan($plan);

        $this->assertSame(['stale_at_publication'], $result['items'][0]['risk_flags']);
        $prompt = (string) data_get(Http::recorded()[0][0]->data(), 'messages.1.content');
        $this->assertStringContainsString('stale_at_publication', $prompt);
        $this->assertStringContainsString('"plan_date":"2026-07-27"', $prompt);
        $this->assertStringContainsString('"publication_timezone":"Europe\/Moscow"', $prompt);
        $this->assertStringContainsString('"scheduled_at":"2026-07-27T12:00:00+03:00"', $prompt);
        $this->assertStringContainsString('"posted_at":"2026-07-26T15:00:00+00:00"', $prompt);
        $this->assertSame('v6', AiRun::query()->sole()->prompt_version);
    }

    public function test_publication_builds_each_configured_signature_variant(): void
    {
        config(['services.telegram.messenger_base_url' => 'https://t.me']);
        $publication = Publication::factory()->create([
            'name' => 'ПокаТренд',
            'signature_mode' => PublicationSignatureMode::Username,
        ]);
        $destination = Destination::factory()->create([
            'publication_id' => $publication->id,
            'external_id' => '@PokaTrend',
        ]);

        $this->assertSame('@PokaTrend', $publication->signatureMarkdown($destination));

        $publication->update([
            'signature_mode' => PublicationSignatureMode::Link,
            'signature_label' => 'ПокаТренд',
        ]);
        $this->assertSame('[ПокаТренд](https://t.me/PokaTrend)', $publication->fresh()->signatureMarkdown($destination));

        $publication->update(['signature_mode' => PublicationSignatureMode::None]);
        $this->assertNull($publication->fresh()->signatureMarkdown($destination));
    }

    public function test_pokatrend_migration_moves_legacy_signature_from_tone_to_link_setting(): void
    {
        $legacyInstruction = 'После смысловой концовки оставь пустую строку и добавь отдельной последней строкой строго @PokaTrend — без точки, запятой, эмодзи и любых других знаков после подписи.';
        $publication = Publication::factory()->create([
            'slug' => 'pokatrend',
            'tone_prompt' => "Живой редакционный тон.\n\n{$legacyInstruction}",
            'signature_mode' => PublicationSignatureMode::None,
        ]);
        $destination = Destination::factory()->create([
            'publication_id' => $publication->id,
            'external_id' => '@pokatrend',
        ]);
        $migration = require database_path('migrations/2026_07_25_115107_set_pokatrend_signature_defaults.php');

        $migration->up();

        $publication->refresh();
        $this->assertSame('Живой редакционный тон.', $publication->tone_prompt);
        $this->assertSame(PublicationSignatureMode::Link, $publication->signature_mode);
        $this->assertSame('ПокаТренд', $publication->signature_label);
        $this->assertSame('@PokaTrend', $destination->fresh()->external_id);
    }

    public function test_pokatrend_editorial_instruction_migration_only_replaces_the_known_legacy_prompt(): void
    {
        $legacyPrompt = $this->legacyPokaTrendEditorialInstruction();
        $publication = Publication::factory()->create([
            'slug' => 'pokatrend',
            'tone_prompt' => $legacyPrompt,
        ]);
        $customPublication = Publication::factory()->create([
            'tone_prompt' => 'Моя вручную настроенная редакционная инструкция.',
        ]);
        $migration = require database_path('migrations/2026_07_25_162149_update_pokatrend_editorial_instructions.php');

        $migration->up();

        $updatedPrompt = $publication->fresh()->tone_prompt;
        $this->assertStringContainsString('Выбирай форму под сам инфоповод, а не под единый шаблон.', $updatedPrompt);
        $this->assertStringContainsString('Цитаты оформляй отдельным Markdown-блоком через `>`', $updatedPrompt);
        $this->assertStringContainsString('Отдельный вывод не обязателен.', $updatedPrompt);
        $this->assertStringNotContainsString('обычно 350–700 знаков', $updatedPrompt);
        $this->assertSame(
            'Моя вручную настроенная редакционная инструкция.',
            $customPublication->fresh()->tone_prompt,
        );

        $migration->down();

        $this->assertSame($legacyPrompt, $publication->fresh()->tone_prompt);

        $publication->update(['tone_prompt' => 'Настройка PokaTrend, изменённая вручную.']);
        $migration->up();

        $this->assertSame('Настройка PokaTrend, изменённая вручную.', $publication->fresh()->tone_prompt);
    }

    public function test_candidate_generation_filters_ads_and_creates_clustered_candidates(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $news = SourcePost::factory()->create(['source_id' => $channel->id, 'text' => 'Запущен важный городской проект']);
        SourcePost::factory()->create(['source_id' => $channel->id, 'text' => 'Реклама и промокод внутри']);

        $fake = new class($news->id) implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedPostIds = [];

            public function __construct(private readonly int $sourcePostId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedPostIds = array_values(
                    $sourcePosts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                );

                return ['clusters' => [[
                    'source_post_ids' => [$this->sourcePostId],
                    'title' => 'Городской проект',
                    'summary' => 'Краткое описание',
                    'score' => 91,
                    'score_breakdown' => ['reach' => 90],
                    'selection_reason' => 'Высокая значимость',
                    'risk_flags' => [],
                ]]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle($plan);

        $this->assertSame([$news->id], $fake->receivedPostIds);
        $this->assertDatabaseCount('story_candidates', 1);
        $this->assertDatabaseHas('story_candidates', ['content_plan_id' => $plan->id, 'title' => 'Городской проект']);
        $this->assertDatabaseHas('source_post_story_candidate', ['source_post_id' => $news->id, 'is_primary' => true]);
    }

    public function test_candidate_generation_excludes_weather_that_expires_before_the_plan_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-26 19:00:00', 'Europe/Moscow'));
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create([
            'source_group_id' => $group->id,
            'timezone' => 'Europe/Moscow',
            'publish_window_start' => '09:00',
            'publish_window_end' => '09:00',
            'reserve_multiplier' => 1,
        ]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-27',
        ]);
        $staleDatedWeather = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Погода 26 июля: в Петербурге ожидается дождь.',
            'posted_at' => now()->subHour(),
        ]);
        $staleRelativeWeather = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Сегодняшняя погода будет дождливой.',
            'posted_at' => now()->subHour(),
        ]);
        $currentWeather = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Завтра погода будет солнечной.',
            'posted_at' => now()->subHour(),
        ]);
        $cityNews = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'В Петербурге открыли новую станцию метро.',
            'posted_at' => now()->subHour(),
        ]);
        $fake = new class($cityNews->id) implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedPostIds = [];

            public function __construct(private readonly int $sourcePostId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedPostIds = array_values(
                    $sourcePosts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                );

                return ['clusters' => [[
                    'source_post_ids' => [$this->sourcePostId],
                    'title' => 'Новая станция метро',
                    'summary' => 'Станция начала принимать пассажиров.',
                    'score' => 90,
                    'risk_flags' => [],
                ]]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle($plan);

        $this->assertNotContains($staleDatedWeather->id, $fake->receivedPostIds);
        $this->assertNotContains($staleRelativeWeather->id, $fake->receivedPostIds);
        $this->assertContains($currentWeather->id, $fake->receivedPostIds);
        $this->assertContains($cityNews->id, $fake->receivedPostIds);
        $this->assertDatabaseHas('story_candidates', [
            'content_plan_id' => $plan->id,
            'title' => 'Новая станция метро',
        ]);
    }

    public function test_candidate_generation_excludes_approved_sources_from_previous_plans_for_the_same_publication(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $previousPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => today(),
        ]);
        $currentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => today()->addDay(),
        ]);
        $blockedSourceIds = [];

        foreach ([CandidateStatus::Approved, CandidateStatus::Reserve, CandidateStatus::Selected] as $status) {
            $sourcePost = SourcePost::factory()->create([
                'source_id' => $channel->id,
                'text' => 'Одобренная новость '.$status->value,
                'posted_at' => now()->subHour(),
            ]);
            $candidate = StoryCandidate::factory()->create([
                'content_plan_id' => $previousPlan->id,
                'status' => $status,
            ]);
            $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
            $blockedSourceIds[] = $sourcePost->id;
        }

        $eligibleSourceIds = [];

        foreach ([CandidateStatus::Pending, CandidateStatus::Rejected] as $status) {
            $sourcePost = SourcePost::factory()->create([
                'source_id' => $channel->id,
                'text' => 'Неиспользованная новость '.$status->value,
                'posted_at' => now()->subHour(),
            ]);
            $candidate = StoryCandidate::factory()->create([
                'content_plan_id' => $previousPlan->id,
                'status' => $status,
            ]);
            $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
            $eligibleSourceIds[] = $sourcePost->id;
        }

        $otherPublication = Publication::factory()->create(['source_group_id' => $group->id]);
        $otherPlan = ContentPlan::factory()->create([
            'publication_id' => $otherPublication->id,
            'plan_date' => today(),
        ]);
        $otherPublicationSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Новость другого канала публикаций',
            'posted_at' => now()->subHour(),
        ]);
        $otherPublicationCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $otherPlan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $otherPublicationCandidate->sourcePosts()->attach($otherPublicationSource, ['is_primary' => true]);
        $eligibleSourceIds[] = $otherPublicationSource->id;

        $freshSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'text' => 'Новая новость',
            'posted_at' => now()->subHour(),
        ]);
        $eligibleSourceIds[] = $freshSource->id;

        $fake = new class implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedPostIds = [];

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedPostIds = array_values(
                    $sourcePosts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                );

                return ['clusters' => []];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle($currentPlan);

        $this->assertSame(
            collect($eligibleSourceIds)->sort()->values()->all(),
            collect($fake->receivedPostIds)->sort()->values()->all(),
        );
        $this->assertEmpty(collect($fake->receivedPostIds)->intersect($blockedSourceIds));
    }

    public function test_candidate_regeneration_preserves_decisions_and_replaces_only_pending_candidates(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create([
            'source_group_id' => $group->id,
            'publish_window_start' => '09:00',
            'publish_window_end' => '09:00',
            'reserve_multiplier' => 2,
        ]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $approvedSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $rejectedSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $pendingSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $freshSource = SourcePost::factory()->create([
            'source_id' => $channel->id,
            'posted_at' => now()->subHour(),
        ]);
        $approvedCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $approvedCandidate->sourcePosts()->attach($approvedSource, ['is_primary' => true]);
        $rejectedCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Rejected,
        ]);
        $rejectedCandidate->sourcePosts()->attach($rejectedSource, ['is_primary' => true]);
        $pendingCandidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Pending,
        ]);
        $pendingCandidate->sourcePosts()->attach($pendingSource, ['is_primary' => true]);

        $fake = new class($freshSource->id) implements ContentIntelligence
        {
            /** @var list<int> */
            public array $receivedPostIds = [];

            public function __construct(private readonly int $freshSourceId) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                $this->receivedPostIds = array_values(
                    $sourcePosts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                );

                return ['clusters' => [[
                    'source_post_ids' => [$this->freshSourceId],
                    'title' => 'Новая замена',
                    'summary' => 'Новая новость вместо кандидата без решения',
                    'score' => 80,
                    'risk_flags' => [],
                ]]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle($plan);

        $this->assertSame([$freshSource->id], $fake->receivedPostIds);
        $this->assertModelExists($approvedCandidate);
        $this->assertModelExists($rejectedCandidate);
        $this->assertModelMissing($pendingCandidate);
        $this->assertSame(CandidateStatus::Approved, $approvedCandidate->fresh()->status);
        $this->assertSame(CandidateStatus::Rejected, $rejectedCandidate->fresh()->status);
        $this->assertSame(2, $plan->fresh()->candidate_target);
        $this->assertSame(2, $plan->storyCandidates()
            ->whereIn('status', [CandidateStatus::Pending, CandidateStatus::Approved])
            ->count());
        $this->assertDatabaseHas('story_candidates', [
            'content_plan_id' => $plan->id,
            'title' => 'Новая замена',
            'status' => CandidateStatus::Pending,
        ]);
    }

    public function test_candidate_generation_prevents_source_reuse_and_prefers_primary_source_with_media(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = Source::factory()->create();
        $group->sources()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $textOnly = SourcePost::factory()->create(['source_id' => $channel->id]);
        $withMedia = SourcePost::factory()->create(['source_id' => $channel->id]);
        $withMedia->mediaAssets()->create([
            'external_id' => 'photo-primary',
            'type' => MediaType::Photo,
            'disk' => 'local',
            'path' => 'telegram/photo-primary.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $fake = new class($textOnly->id, $withMedia->id) implements ContentIntelligence
        {
            public function __construct(
                private readonly int $textOnlyId,
                private readonly int $withMediaId,
            ) {}

            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                return ['clusters' => [
                    [
                        'source_post_ids' => [$this->textOnlyId, $this->withMediaId],
                        'title' => 'Один инфоповод',
                        'summary' => 'Два подтверждающих источника',
                        'score' => 90,
                        'risk_flags' => [],
                    ],
                    [
                        'source_post_ids' => [$this->textOnlyId],
                        'title' => 'Ошибочный повтор',
                        'summary' => 'Тот же source post не должен использоваться снова',
                        'score' => 80,
                        'risk_flags' => [],
                    ],
                ]];
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => [], 'duplicate_groups' => []];
            }
        };
        $this->app->instance(ContentIntelligence::class, $fake);

        app(GenerateCandidateBatch::class)->handle($plan);

        $candidate = $plan->storyCandidates()->firstOrFail();
        $this->assertSame(1, $plan->storyCandidates()->count());
        $this->assertDatabaseHas('source_post_story_candidate', [
            'story_candidate_id' => $candidate->id,
            'source_post_id' => $withMedia->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('source_post_story_candidate', [
            'story_candidate_id' => $candidate->id,
            'source_post_id' => $textOnly->id,
            'is_primary' => false,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @return array<string, mixed>
     */
    private function openRouterResponse(array $clusters): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode(['clusters' => $clusters], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function rewriteResponse(string $text): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'text' => $text,
                        'risk_flags' => [],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => [],
        ];
    }

    private function legacyPokaTrendEditorialInstruction(): string
    {
        return <<<'PROMPT'
Пиши как редактор живого Telegram-канала о трендах, технологиях, брендах и необычных событиях.

Главный принцип: конкретный факт → понятное объяснение → одна точная реакция. Интерес должен появляться из деталей новости, а не из повторяющихся мемных выражений.

Начинай сразу с самого интересного факта. Не дублируй одну мысль в заголовке и первом абзаце. Используй 2–4 коротких абзаца, обычно 350–700 знаков. Сохраняй важные имена, названия, цены, цифры и обстоятельства.

Тон разговорный, современный и уверенный. Можно добавить лёгкую иронию, неожиданное сравнение или короткий панч в конце. Не больше одной шутки на пост. Шутка должна быть связана с конкретной новостью, а не состоять из универсального сленга.

Меняй структуру и ритм постов: не начинай и не заканчивай публикации одинаково. Не используй постоянные фирменные обороты внутри каждого текста.

Для странных товаров, маркетинга и поп-культуры допустим более ироничный тон. Для экономики и технологий — простой и информативный, с понятной аналогией. Для аварий, конфликтов и серьёзных происшествий — сдержанный, без шуток и обесценивания.

Используй не больше одного тематического эмодзи. Не выдумывай реакции людей, мотивы компаний или дополнительные факты. Не преувеличивай значение события и не используй кликбейт.

Завершай пост коротким осмысленным выводом, наблюдением или точной шуткой, связанной именно с этой новостью. Для серьёзных тем используй спокойный вывод без юмора. Не заканчивай текст пустой универсальной реакцией. Если уместного вывода нет, закончи на самом сильном конкретном факте.
PROMPT;
    }
}
