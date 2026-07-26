<?php

namespace Tests\Feature;

use App\Actions\BuildContentPlan;
use App\Actions\GenerateCandidateBatch;
use App\Actions\QueueContentPlanGeneration;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourceGroup;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EditorialWorkflowReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_generation_can_only_be_queued_once(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create();
        $action = app(QueueContentPlanGeneration::class);

        $this->assertTrue($action->handle($plan));
        $this->assertFalse($action->handle($plan->fresh()));
        $this->assertSame(ContentPlanStatus::Generating, $plan->fresh()->status);
        Queue::assertPushed(GenerateCandidateBatchJob::class, 1);
    }

    public function test_candidate_generation_cannot_be_queued_after_planned_posts_exist(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);

        $this->assertFalse(app(QueueContentPlanGeneration::class)->handle($plan));
        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_generated_candidate_batch_is_not_automatically_queued_again(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'generated_at' => now(),
        ]);

        $this->assertFalse(app(QueueContentPlanGeneration::class)->handle($plan));
        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_generated_candidate_batch_can_be_explicitly_queued_for_regeneration(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'generated_at' => now(),
        ]);

        $this->assertTrue(app(QueueContentPlanGeneration::class)->handle(
            $plan,
            allowRegeneration: true,
        ));
        $this->assertSame(ContentPlanStatus::Generating, $plan->fresh()->status);
        Queue::assertPushed(GenerateCandidateBatchJob::class, 1);
    }

    public function test_empty_candidate_batch_is_marked_as_generated(): void
    {
        $group = SourceGroup::factory()->create();
        $publication = Publication::factory()->create(['source_group_id' => $group->id]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'status' => ContentPlanStatus::Generating,
        ]);
        $this->app->instance(ContentIntelligence::class, new class implements ContentIntelligence
        {
            public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
            {
                throw new RuntimeException('Ranking must not run for an empty batch.');
            }

            public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
            {
                return ['text' => ''];
            }

            public function reviewPlan(ContentPlan $contentPlan): array
            {
                return ['items' => []];
            }
        });

        app(GenerateCandidateBatch::class)->handle($plan);

        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->generated_at);
    }

    public function test_building_a_plan_twice_does_not_duplicate_posts_or_jobs(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $action = app(BuildContentPlan::class);

        $action->handle($plan);
        $action->handle($plan->fresh());

        $this->assertDatabaseCount('planned_posts', 1);
        Queue::assertPushed(RewritePlannedPostJob::class, 1);
    }

    public function test_failed_rewrite_marks_post_and_plan_for_retry(): void
    {
        $plan = ContentPlan::factory()->create(['status' => ContentPlanStatus::Rewriting]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Rewriting,
        ]);

        (new RewritePlannedPostJob($post->id))->failed(new RuntimeException('AI unavailable'));

        $this->assertSame(PlannedPostStatus::Failed, $post->fresh()->status);
        $this->assertSame(ContentPlanStatus::Failed, $plan->fresh()->status);
        $this->assertSame('AI unavailable', $post->fresh()->failure_reason);
    }
}
