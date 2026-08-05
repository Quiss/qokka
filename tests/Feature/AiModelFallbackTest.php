<?php

namespace Tests\Feature;

use App\Actions\GenerateCandidateBatch;
use App\ContentPlanStatus;
use App\Contracts\FallbackContentIntelligence;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Jobs\ReviewContentPlanJob;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use App\PublicationSignatureMode;
use App\Services\OpenRouterContentIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiModelFallbackTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('rewriteAttempts')]
    public function test_rewrite_job_uses_fallback_model_only_on_the_final_attempt(
        int $attempts,
        string $expectedModel,
    ): void {
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
            'services.openrouter.rewrite_model' => 'default-rewrite-model',
            'services.openrouter.rewrite_fallback_model' => 'fallback-rewrite-model',
        ]);
        $publication = Publication::factory()->create([
            'rewrite_model' => 'publication-rewrite-model',
            'signature_mode' => PublicationSignatureMode::None,
        ]);
        $contentPlan = ContentPlan::factory()->create(['publication_id' => $publication->id]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $contentPlan->id]);
        $sourcePost = SourcePost::factory()->create(['text' => 'Подтвержденный исходный факт.']);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Rewriting,
            'rewrite_generation' => 0,
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response($this->rewriteResponse('Готовый рекламный текст.')),
        ]);
        $queueJob = new FakeJob;
        $queueJob->attempts = $attempts;
        $job = (new RewritePlannedPostJob($plannedPost->id))->setJob($queueJob);

        $job->handle(app(OpenRouterContentIntelligence::class));

        Http::assertSent(fn (Request $request): bool => $request['model'] === $expectedModel);
        $this->assertSame('Готовый рекламный текст.', $plannedPost->fresh()->text);
    }

    /** @return iterable<string, array{int, string}> */
    public static function rewriteAttempts(): iterable
    {
        yield 'second attempt keeps publication model' => [2, 'publication-rewrite-model'];
        yield 'third attempt uses fallback model' => [3, 'fallback-rewrite-model'];
    }

    public function test_candidate_generation_uses_analysis_fallback_on_the_final_attempt(): void
    {
        Http::preventStrayRequests();
        config([
            'services.openrouter.key' => 'test-key',
            'services.openrouter.url' => 'https://openrouter.test/api/v1',
            'services.openrouter.analysis_model' => 'default-analysis-model',
            'services.openrouter.analysis_fallback_model' => 'fallback-analysis-model',
        ]);
        $sourceGroup = SourceGroup::factory()->create();
        $source = Source::factory()->create();
        $sourceGroup->sources()->attach($source);
        $publication = Publication::factory()->create([
            'source_group_id' => $sourceGroup->id,
            'analysis_model' => 'publication-analysis-model',
        ]);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Generating,
        ]);
        SourcePost::factory()->create([
            'source_id' => $source->id,
            'text' => 'В городе открыли новый общественный центр.',
            'posted_at' => now()->subHour(),
        ]);
        Http::fake([
            'https://openrouter.test/*' => Http::response($this->rankingResponse()),
        ]);
        $queueJob = new FakeJob;
        $queueJob->attempts = 3;
        $job = (new GenerateCandidateBatchJob($contentPlan->id))->setJob($queueJob);

        $job->handle(app(GenerateCandidateBatch::class));

        Http::assertSent(fn (Request $request): bool => $request['model'] === 'fallback-analysis-model');
        $this->assertSame(ContentPlanStatus::CandidateReview, $contentPlan->fresh()->status);
    }

    public function test_plan_review_uses_analysis_fallback_on_the_final_attempt(): void
    {
        $contentPlan = ContentPlan::factory()->create();
        $intelligence = Mockery::mock(FallbackContentIntelligence::class);
        $intelligence->shouldNotReceive('reviewPlan');
        $intelligence->shouldReceive('reviewPlanWithFallback')
            ->once()
            ->andReturn(['items' => [], 'duplicate_groups' => []]);
        $queueJob = new FakeJob;
        $queueJob->attempts = 3;
        $job = (new ReviewContentPlanJob($contentPlan->id))->setJob($queueJob);

        $job->handle($intelligence);

        $this->assertSame(ContentPlanStatus::FinalReview, $contentPlan->fresh()->status);
    }

    public function test_candidate_replenishment_forwards_fallback_on_the_final_attempt(): void
    {
        $contentPlan = ContentPlan::factory()->create(['status' => ContentPlanStatus::Generating]);
        $generateCandidateBatch = Mockery::mock(GenerateCandidateBatch::class);
        $generateCandidateBatch->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                ContentPlan $plan,
                bool $append,
                int $lookbackHours,
                int $targetOverride,
                bool $useFallbackModel,
            ): bool => $plan->is($contentPlan)
                && $append
                && $lookbackHours === 24
                && $targetOverride === 5
                && $useFallbackModel)
            ->andReturn($contentPlan);
        $queueJob = new FakeJob;
        $queueJob->attempts = 3;
        $job = (new ReplenishContentPlanCandidatesJob(
            $contentPlan->id,
            5,
            extendLookback: false,
        ))->setJob($queueJob);

        $job->handle($generateCandidateBatch);

        $this->assertSame(ContentPlanStatus::NeedsCandidates, $contentPlan->fresh()->status);
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

    /** @return array<string, mixed> */
    private function rankingResponse(): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode(['clusters' => []], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => [],
        ];
    }
}
