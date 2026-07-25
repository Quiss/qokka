<?php

namespace Tests\Feature;

use App\Actions\GenerateCandidateBatch;
use App\Contracts\ContentIntelligence;
use App\MediaType;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourceChannel;
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

    public function test_open_router_ranking_serializes_immutable_posted_at_and_enum_media(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $channel = SourceChannel::factory()->create();
        $plan = ContentPlan::factory()->create();
        $sourcePost = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
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
        $sourcePost = $sourcePost->fresh(['sourceChannel', 'mediaAssets']);
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
        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) data_get($request->data(), 'messages.1.content.0.text'),
            '"media":["photo"]',
        ));
    }

    public function test_open_router_validates_draft_cluster_sources_before_returning_them(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
        $channel = SourceChannel::factory()->create();
        $plan = ContentPlan::factory()->create(['candidate_target' => 2]);
        $lightning = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'text' => 'Молния ударила в ракету во время запуска на космодроме.',
        ]);
        $nettle = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'text' => 'Во ВкусВилл появился набор для битья крапивы.',
        ]);
        $posts = SourcePost::query()
            ->with(['sourceChannel', 'mediaAssets'])
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

    public function test_rewrite_uses_markdown_tone_rules_and_retries_an_invalid_signature_once(): void
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
            'tone_examples' => ['Коротко и живо'],
        ]);
        Destination::factory()->create([
            'publication_id' => $publication->id,
            'external_id' => '@PokaTrend',
        ]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $sourcePost = SourcePost::factory()->create([
            'text' => 'Компания представила новый продукт.',
        ]);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
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
        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) data_get($request->data(), 'messages.1.content.0.text'),
            'Разрешена обычная разметка Markdown',
        ) && str_contains(
            (string) data_get($request->data(), 'messages.1.content.0.text'),
            '[ПокаТренд](https://t.me/PokaTrend)',
        ));
        $this->assertSame(['v2', 'v2'], AiRun::query()->orderBy('id')->pluck('prompt_version')->all());
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

    public function test_candidate_generation_filters_ads_and_creates_clustered_candidates(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = SourceChannel::factory()->create();
        $group->sourceChannels()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $news = SourcePost::factory()->create(['source_channel_id' => $channel->id, 'text' => 'Запущен важный городской проект']);
        SourcePost::factory()->create(['source_channel_id' => $channel->id, 'text' => 'Реклама и промокод внутри']);

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

    public function test_candidate_generation_prevents_source_reuse_and_prefers_primary_source_with_media(): void
    {
        $group = SourceGroup::factory()->create();
        $channel = SourceChannel::factory()->create();
        $group->sourceChannels()->attach($channel);
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $textOnly = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
        $withMedia = SourcePost::factory()->create(['source_channel_id' => $channel->id]);
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
}
