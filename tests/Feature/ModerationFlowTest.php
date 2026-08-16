<?php

namespace Tests\Feature;

use App\Actions\ApprovePlannedPost;
use App\Actions\ApproveStoryCandidate;
use App\Actions\BuildContentPlan;
use App\Actions\PopulateContentPlanSafetyNet;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\DeliveryStatus;
use App\Jobs\DownloadMediaAssetJob;
use App\Jobs\RewritePlannedPostJob;
use App\MediaType;
use App\Models\ContentPlan;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\PlannedPostStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

    public function test_plan_approval_copies_an_animation_from_the_primary_source(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $animation = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Animation,
        ]);

        $builtPlan = app(BuildContentPlan::class)->handle($plan);

        $plannedPost = $builtPlan->plannedPosts->sole();

        $this->assertSame(ContentPlanStatus::Rewriting, $builtPlan->status);
        $this->assertSame(
            [$animation->id],
            $plannedPost->mediaAssets()->pluck('origin_media_asset_id')->all(),
        );
        Queue::assertPushed(RewritePlannedPostJob::class);
    }

    public function test_plan_approval_skips_oversized_media_and_uses_an_alternative_source(): void
    {
        Queue::fake();
        config(['services.telegram.media_max_bytes' => 500 * 1024 * 1024]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $primarySource = SourcePost::factory()->create();
        $alternativeSource = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($primarySource, ['is_primary' => true]);
        $candidate->sourcePosts()->attach($alternativeSource, ['is_primary' => false]);
        $oversizedVideo = MediaAsset::factory()->for($primarySource, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => null,
            'size_bytes' => 501 * 1024 * 1024,
            'downloaded_at' => null,
        ]);
        $alternativePhoto = MediaAsset::factory()->for($alternativeSource, 'mediable')->create();

        $builtPlan = app(BuildContentPlan::class)->handle($plan);

        $plannedPost = $builtPlan->plannedPosts->sole();
        $this->assertSame(ContentPlanStatus::Rewriting, $builtPlan->status);
        $this->assertSame(
            [$alternativePhoto->id],
            $plannedPost->mediaAssets()->pluck('origin_media_asset_id')->all(),
        );
        $this->assertDatabaseMissing('media_assets', [
            'mediable_type' => PlannedPost::class,
            'mediable_id' => $plannedPost->id,
            'origin_media_asset_id' => $oversizedVideo->id,
        ]);
        Queue::assertPushed(RewritePlannedPostJob::class);
        Queue::assertNotPushed(DownloadMediaAssetJob::class);
    }

    public function test_plan_with_only_oversized_media_is_built_as_text_only(): void
    {
        Queue::fake();
        config(['services.telegram.media_max_bytes' => 500 * 1024 * 1024]);
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
            'status' => CandidateStatus::Approved,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'type' => MediaType::Video,
            'path' => null,
            'size_bytes' => 501 * 1024 * 1024,
            'downloaded_at' => null,
        ]);

        $builtPlan = app(BuildContentPlan::class)->handle($plan);

        $this->assertSame(ContentPlanStatus::Rewriting, $builtPlan->status);
        $this->assertSame(0, $builtPlan->plannedPosts->sole()->mediaAssets()->count());
        Queue::assertPushed(RewritePlannedPostJob::class);
        Queue::assertNotPushed(DownloadMediaAssetJob::class);
    }

    public function test_approved_short_plan_is_built_without_replenishment(): void
    {
        Queue::fake();
        $slots = [
            now()->addDay()->toIso8601String(),
            now()->addDay()->addHours(2)->toIso8601String(),
            now()->addDay()->addHours(4)->toIso8601String(),
        ];
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => $slots,
        ]);
        $candidates = StoryCandidate::factory()
            ->count(2)
            ->create([
                'content_plan_id' => $plan->id,
                'status' => CandidateStatus::Approved,
            ]);

        foreach ($candidates as $candidate) {
            $sourcePost = SourcePost::factory()->create();
            $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        }

        app(BuildContentPlan::class)->handle($plan);

        $builtPlan = $plan->fresh();

        $this->assertSame(ContentPlanStatus::Rewriting, $builtPlan->status);
        $this->assertSame([$slots[0], $slots[2]], $builtPlan->slot_schedule);
        $this->assertCount(2, $builtPlan->plannedPosts);
        $this->assertSame(0, app(PopulateContentPlanSafetyNet::class)->futureVacantSlotCount($builtPlan));
        Queue::assertPushed(RewritePlannedPostJob::class, 2);
    }

    public function test_plan_cannot_be_built_without_approved_candidates(): void
    {
        Queue::fake();
        $plan = ContentPlan::factory()->create([
            'status' => ContentPlanStatus::CandidateReview,
            'slot_schedule' => [now()->addDay()->toIso8601String()],
        ]);

        try {
            app(BuildContentPlan::class)->handle($plan);
            $this->fail('A plan without approved candidates should not be built.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Одобрите хотя бы одного кандидата перед запуском рерайта.',
                $exception->errors()['candidates'][0],
            );
        }

        $this->assertSame(ContentPlanStatus::CandidateReview, $plan->fresh()->status);
        $this->assertDatabaseCount('planned_posts', 0);
        Queue::assertNothingPushed();
    }
}
