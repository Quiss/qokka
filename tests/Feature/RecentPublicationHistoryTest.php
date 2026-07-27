<?php

namespace Tests\Feature;

use App\ContentPlanStatus;
use App\Jobs\ReviewContentPlanJob;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourceChannel;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use App\Services\OpenRouterContentIntelligence;
use App\Services\RecentPublicationHistory;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecentPublicationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_contains_only_recent_committed_posts_from_the_same_publication(): void
    {
        $this->travelTo('2026-07-27 12:00:00');
        config(['channelbot.content.duplicate_lookback_days' => 14]);

        $publication = Publication::factory()->create(['timezone' => 'Europe/Moscow']);
        $currentPlan = ContentPlan::factory()->for($publication)->create();
        $previousPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDay()->toDateString(),
        ]);

        $recentPublished = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Недавняя опубликованная новость',
            publishedAt: now()->subDays(13),
        );
        $boundaryPublished = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Новость на границе окна',
            publishedAt: now()->subDays(14),
        );
        $approved = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Approved,
            'Одобренная будущая новость',
            scheduledAt: now()->addDay(),
        );
        $publishing = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Publishing,
            'Публикующаяся новость',
            scheduledAt: now()->addHours(2),
        );
        $oldPublished = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Слишком старая новость',
            publishedAt: now()->subDays(14)->subSecond(),
        );
        $cancelled = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Cancelled,
            'Отменённая новость',
            scheduledAt: now()->addHour(),
        );
        $currentPlanPost = $this->createPlannedPost(
            $currentPlan,
            PlannedPostStatus::Published,
            'Пост текущего плана',
            publishedAt: now()->subHour(),
        );

        $otherPublication = Publication::factory()->create();
        $otherPlan = ContentPlan::factory()->for($otherPublication)->create();
        $otherPublicationPost = $this->createPlannedPost(
            $otherPlan,
            PlannedPostStatus::Published,
            'Новость другого канала',
            publishedAt: now()->subHour(),
        );

        $history = app(RecentPublicationHistory::class)->forPlan($currentPlan);
        $historyIds = array_column($history, 'id');

        $this->assertEqualsCanonicalizing(
            [
                $recentPublished->id,
                $boundaryPublished->id,
                $approved->id,
                $publishing->id,
            ],
            $historyIds,
        );
        $this->assertNotContains($oldPublished->id, $historyIds);
        $this->assertNotContains($cancelled->id, $historyIds);
        $this->assertNotContains($currentPlanPost->id, $historyIds);
        $this->assertNotContains($otherPublicationPost->id, $historyIds);

        $publishedHistory = collect($history)->firstWhere('id', $recentPublished->id);
        $this->assertIsArray($publishedHistory);
        $this->assertSame('Недавняя опубликованная новость', $publishedHistory['title']);
        $this->assertStringContainsString('+03:00', $publishedHistory['at']);
        $this->assertSame(['id', 'title', 'summary', 'at'], array_keys($publishedHistory));
    }

    public function test_history_is_limited_to_the_latest_committed_posts(): void
    {
        config([
            'channelbot.content.duplicate_lookback_days' => 14,
            'channelbot.content.duplicate_history_limit' => 2,
        ]);

        $publication = Publication::factory()->create();
        $currentPlan = ContentPlan::factory()->for($publication)->create();
        $previousPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDay()->toDateString(),
        ]);
        $oldest = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Самая ранняя публикация',
            publishedAt: now()->subDays(3),
        );
        $middle = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Средняя публикация',
            publishedAt: now()->subDays(2),
        );
        $latest = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'Последняя публикация',
            publishedAt: now()->subDay(),
        );

        $historyIds = array_column(
            app(RecentPublicationHistory::class)->forPlan($currentPlan),
            'id',
        );

        $this->assertSame([$latest->id, $middle->id], $historyIds);
        $this->assertNotContains($oldest->id, $historyIds);
    }

    public function test_history_sends_only_compact_text_fragments(): void
    {
        $publication = Publication::factory()->create();
        $currentPlan = ContentPlan::factory()->for($publication)->create();
        $previousPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDay()->toDateString(),
        ]);
        $historicalPost = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            str_repeat('З', 220),
            publishedAt: now()->subDay(),
        );
        $historicalPost->storyCandidate->update(['summary' => null]);
        $historicalPost->update(['text' => str_repeat('Т', 500)]);

        $historyItem = app(RecentPublicationHistory::class)->forPlan($currentPlan)[0];

        $this->assertSame(160, Str::length($historyItem['title']));
        $this->assertSame(180, Str::length($historyItem['summary']));
        $this->assertStringNotContainsString(str_repeat('Т', 181), $historyItem['summary']);
    }

    public function test_ranking_receives_recent_history_without_repeating_it_in_validation(): void
    {
        $this->travelTo('2026-07-27 12:00:00');
        Http::preventStrayRequests();
        config([
            'channelbot.content.duplicate_lookback_days' => 14,
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);

        $publication = Publication::factory()->create();
        $previousPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDay()->toDateString(),
        ]);
        $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'В городе открыли новую станцию метро',
            publishedAt: now()->subDay(),
        );
        $currentPlan = ContentPlan::factory()->for($publication)->create();
        $sourcePost = SourcePost::factory()->for(SourceChannel::factory())->create([
            'text' => 'Сегодня начала работать новая станция метро.',
            'posted_at' => now()->subHour(),
        ])->load(['sourceChannel', 'mediaAssets']);
        $duplicateCluster = [[
            'source_post_ids' => [$sourcePost->id],
            'title' => 'Открылась новая станция метро',
            'summary' => 'Станция начала принимать пассажиров.',
            'score' => 90,
            'score_breakdown' => [],
            'selection_reason' => 'Важная городская новость.',
            'risk_flags' => [],
            'source_conflicts' => [],
        ]];
        Http::fake([
            'https://openrouter.test/*' => Http::sequence()
                ->push($this->openRouterRankingResponse($duplicateCluster))
                ->push($this->openRouterRankingResponse([])),
        ]);

        $result = app(OpenRouterContentIntelligence::class)
            ->rankAndCluster($currentPlan, $sourcePost->newCollection([$sourcePost]));

        $this->assertSame([], $result['clusters']);
        Http::assertSentCount(2);
        $rankingPrompt = (string) data_get(Http::recorded()[0][0]->data(), 'messages.1.content.0.text');
        $validationPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'messages.1.content.0.text');
        $this->assertStringContainsString('recent_committed_posts', $rankingPrompt);
        $this->assertStringContainsString('В городе открыли новую станцию метро', $rankingPrompt);
        $this->assertStringNotContainsString('recent_committed_posts', $validationPrompt);
        $this->assertStringNotContainsString('В городе открыли новую станцию метро', $validationPrompt);
        $this->assertSame(
            ['v5', 'v5'],
            AiRun::query()->oldest('id')->pluck('prompt_version')->all(),
        );
    }

    public function test_final_review_blocks_a_recently_published_duplicate(): void
    {
        $this->travelTo('2026-07-27 12:00:00');
        Http::preventStrayRequests();
        config([
            'channelbot.content.duplicate_lookback_days' => 14,
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);

        $publication = Publication::factory()->create();
        $previousPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDay()->toDateString(),
        ]);
        $historicalPost = $this->createPlannedPost(
            $previousPlan,
            PlannedPostStatus::Published,
            'В городе открыли новую станцию метро',
            publishedAt: now()->subDay(),
        );

        $currentPlan = ContentPlan::factory()->for($publication)->create([
            'status' => ContentPlanStatus::FinalReview,
        ]);
        $candidate = StoryCandidate::factory()->for($currentPlan)->create();
        $sourcePost = SourcePost::factory()->create([
            'text' => 'Новая станция метро уже принимает пассажиров.',
            'posted_at' => now()->subHour(),
        ]);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $currentPost = PlannedPost::factory()->create([
            'content_plan_id' => $currentPlan->id,
            'story_candidate_id' => $candidate->id,
            'text' => 'В городе заработала новая станция метро.',
            'status' => PlannedPostStatus::FinalReview,
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'items' => [[
                                'planned_post_id' => $currentPost->id,
                                'risk_flags' => ['duplicate_recent_publication'],
                                'reason' => 'Повторяет historical id '.$historicalPost->id.'.',
                            ]],
                            'duplicate_groups' => [],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
                'usage' => [],
            ]),
        ]);

        (new ReviewContentPlanJob($currentPlan->id))
            ->handle(app(OpenRouterContentIntelligence::class));

        $currentPost->refresh();
        $this->assertSame(PlannedPostStatus::Blocked, $currentPost->status);
        $this->assertSame('blocked', $currentPost->ai_review_status);
        $this->assertContains('duplicate_recent_publication', $currentPost->risk_flags);

        $reviewPrompt = (string) data_get(Http::recorded()[0][0]->data(), 'messages.1.content');
        $this->assertStringContainsString('В городе открыли новую станцию метро', $reviewPrompt);
        $this->assertStringContainsString('duplicate_recent_publication', $reviewPrompt);
        $this->assertSame('v5', AiRun::query()->sole()->prompt_version);
    }

    private function createPlannedPost(
        ContentPlan $contentPlan,
        PlannedPostStatus $status,
        string $title,
        CarbonInterface|string|null $scheduledAt = null,
        CarbonInterface|string|null $publishedAt = null,
    ): PlannedPost {
        $candidate = StoryCandidate::factory()->for($contentPlan)->create([
            'title' => $title,
            'summary' => 'Краткое описание: '.$title,
        ]);

        return PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'status' => $status,
            'scheduled_at' => $scheduledAt ?? now()->addDay(),
            'published_at' => $publishedAt,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $clusters
     * @return array<string, mixed>
     */
    private function openRouterRankingResponse(array $clusters): array
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
}
