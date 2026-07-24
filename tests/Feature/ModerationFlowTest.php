<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\ApproveStoryCandidate;
use App\Actions\BuildContentPlan;
use App\CandidateStatus;
use App\DeliveryStatus;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\Destination;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\PlannedPostStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ModerationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_moderation_is_audited(): void
    {
        $candidate = StoryCandidate::factory()->create();
        $user = User::factory()->create();

        app(ApproveStoryCandidate::class)->approve($candidate, $user);

        $this->assertSame(CandidateStatus::Approved, $candidate->fresh()->status);
        $this->assertDatabaseHas('moderation_actions', ['subject_id' => $candidate->id, 'user_id' => $user->id, 'action' => 'approve_candidate']);
    }

    public function test_ai_block_can_be_approved_without_override_reason_and_creates_delivery(): void
    {
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create(['publication_id' => $publication->id]);
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'slot_schedule' => [now()->addHour()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $plan->id]);
        $post = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => now()->addHour(),
            'status' => PlannedPostStatus::Blocked,
            'risk_flags' => ['possible_duplicate'],
        ]);
        $user = User::factory()->create();

        app(ApprovePlannedPost::class)->approve($post, $user);

        $this->assertSame(PlannedPostStatus::Approved, $post->fresh()->status);
        $this->assertSame($user->id, $post->fresh()->override_by);
        $this->assertNull($post->fresh()->override_reason);
        $this->assertDatabaseHas('deliveries', [
            'planned_post_id' => $post->id,
            'destination_id' => $destination->id,
            'status' => DeliveryStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $post->id,
            'user_id' => $user->id,
            'action' => 'override_ai_block',
            'reason' => null,
        ]);
    }

    public function test_approved_candidates_are_turned_into_scheduled_rewrite_jobs(): void
    {
        Queue::fake();
        $publication = Publication::factory()->create();
        $plan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);

        app(BuildContentPlan::class)->handle($plan);

        $this->assertDatabaseHas('planned_posts', [
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
            'status' => PlannedPostStatus::Rewriting->value,
        ]);
        Queue::assertPushed(RewritePlannedPostJob::class);
    }
}
