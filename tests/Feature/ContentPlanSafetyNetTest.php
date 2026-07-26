<?php

namespace Tests\Feature;

use App\Actions\AdvanceContentPlanSafetyNet;
use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Jobs\RewritePlannedPostJob;
use App\Models\ContentPlan;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\User;
use App\PlannedPostStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentPlanSafetyNetTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_waits_for_the_cutoff_in_the_publication_timezone(): void
    {
        Queue::fake();
        $publication = Publication::factory()->create([
            'timezone' => 'Europe/Moscow',
            'safety_net_cutoff_time' => '10:00',
            'publish_window_end' => '23:00',
        ]);
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::Generating,
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-07-26 06:59:00', 'UTC'));

        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $this->assertNull($contentPlan->fresh()->safety_net_started_at);
        Queue::assertNothingPushed();

        $this->travelTo(CarbonImmutable::parse('2026-07-26 07:00:00', 'UTC'));

        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $this->assertNotNull($contentPlan->fresh()->safety_net_started_at);
        Queue::assertNothingPushed();
    }

    public function test_command_does_not_create_a_current_day_plan_when_none_was_scheduled(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();

        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $this->assertFalse($publication->contentPlans()->exists());
        Queue::assertNothingPushed();
    }

    public function test_command_does_not_generate_an_existing_blank_plan(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => null,
        ]);

        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $this->assertSame(ContentPlanStatus::CandidateReview, $contentPlan->fresh()->status);
        $this->assertNotNull($contentPlan->fresh()->safety_net_started_at);
        Queue::assertNothingPushed();
    }

    public function test_new_publication_only_receives_tomorrow_plan_from_scheduled_generation(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 20:00:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create([
            'planning_time' => '19:30',
        ]);

        $this->artisan('content-plans:generate-due')->assertSuccessful();
        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $contentPlan = $publication->contentPlans()->sole();
        $this->assertSame('2026-07-27', $contentPlan->plan_date->toDateString());
        $this->assertNull($contentPlan->safety_net_started_at);
        Queue::assertPushed(
            GenerateCandidateBatchJob::class,
            fn (GenerateCandidateBatchJob $job): bool => $job->contentPlanId === $contentPlan->id,
        );
    }

    public function test_command_ignores_publications_with_the_safety_net_disabled(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 10:00:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create([
            'safety_net_enabled' => false,
        ]);

        $this->artisan('content-plans:run-safety-net')->assertSuccessful();

        $this->assertFalse($publication->contentPlans()->exists());
        Queue::assertNothingPushed();
    }

    public function test_insufficient_safe_candidates_trigger_one_24_hour_refresh(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => now()->subHour(),
            'slot_schedule' => [
                CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
                CarbonImmutable::parse('2026-07-26 12:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
            ],
        ]);
        $this->createCandidateWithSource($contentPlan);

        $this->assertTrue(app(AdvanceContentPlanSafetyNet::class)->handle($publication));

        $contentPlan->refresh();
        $this->assertSame(ContentPlanStatus::Generating, $contentPlan->status);
        $this->assertNotNull($contentPlan->safety_net_refreshed_at);
        Queue::assertPushed(
            ReplenishContentPlanCandidatesJob::class,
            fn (ReplenishContentPlanCandidatesJob $job): bool => $job->contentPlanId === $contentPlan->id
                && $job->candidateTarget === 1
                && $job->completionStatus === ContentPlanStatus::CandidateReview
                && $job->extendLookback === false,
        );

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        Queue::assertPushed(ReplenishContentPlanCandidatesJob::class, 1);
    }

    public function test_refreshed_plan_populates_only_safe_candidates_and_allows_partial_output(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => [
                CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
                CarbonImmutable::parse('2026-07-26 12:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
            ],
        ]);
        $safeCandidate = $this->createCandidateWithSource($contentPlan, score: 81);
        $riskyCandidate = $this->createCandidateWithSource(
            $contentPlan,
            score: 99,
            riskFlags: ['source_conflict'],
        );

        $this->assertTrue(app(AdvanceContentPlanSafetyNet::class)->handle($publication));

        $plannedPost = $contentPlan->plannedPosts()->sole();
        $this->assertSame($safeCandidate->id, $plannedPost->story_candidate_id);
        $this->assertSame(PlannedPostStatus::Rewriting, $plannedPost->status);
        $this->assertSame(CandidateStatus::Selected, $safeCandidate->fresh()->status);
        $this->assertSame(CandidateStatus::Pending, $riskyCandidate->fresh()->status);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $safeCandidate->id,
            'action' => 'safety_net_select_candidate',
            'user_id' => null,
        ]);
        Queue::assertPushed(
            RewritePlannedPostJob::class,
            fn (RewritePlannedPostJob $job): bool => $job->plannedPostId === $plannedPost->id
                && $job->focusedReview,
        );
    }

    public function test_reviewed_safe_post_is_approved_and_blocked_post_is_skipped_idempotently(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $destination = Destination::factory()->create([
            'publication_id' => $publication->id,
            'is_active' => true,
        ]);
        $slots = [
            CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
            CarbonImmutable::parse('2026-07-26 12:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
        ];
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::FinalReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => $slots,
        ]);
        $safeCandidate = $this->createCandidateWithSource($contentPlan);
        $blockedCandidate = $this->createCandidateWithSource($contentPlan);
        $safePost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $safeCandidate->id,
            'scheduled_at' => $slots[0],
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
            'ai_review_status' => 'passed',
        ]);
        $blockedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $blockedCandidate->id,
            'scheduled_at' => $slots[1],
            'status' => PlannedPostStatus::Blocked,
            'risk_flags' => ['unsupported_claim'],
            'ai_review_status' => 'blocked',
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);
        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(PlannedPostStatus::Approved, $safePost->fresh()->status);
        $this->assertNull($safePost->fresh()->approved_by);
        $this->assertNull($safePost->fresh()->override_by);
        $this->assertSame(PlannedPostStatus::Cancelled, $blockedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Ready, $contentPlan->fresh()->status);
        $this->assertNotNull($contentPlan->fresh()->safety_net_completed_at);
        $this->assertDatabaseHas('deliveries', [
            'planned_post_id' => $safePost->id,
            'destination_id' => $destination->id,
            'status' => 'pending',
        ]);
        $this->assertSame(
            1,
            $safePost->deliveries()->where('destination_id', $destination->id)->count(),
        );
        $this->assertSame(
            1,
            ModerationAction::query()
                ->where('subject_type', PlannedPost::class)
                ->where('subject_id', $safePost->id)
                ->where('action', 'safety_net_approve_post')
                ->count(),
        );
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $blockedPost->id,
            'action' => 'safety_net_skip_post',
            'reason' => 'ai_review_blocked',
        ]);
    }

    public function test_existing_manual_approval_is_never_reversed_by_the_safety_net(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $slot = CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::FinalReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => [$slot],
        ]);
        $candidate = $this->createCandidateWithSource($contentPlan);
        $user = User::factory()->create();
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => $slot,
            'status' => PlannedPostStatus::Approved,
            'risk_flags' => ['possible_duplicate'],
            'approved_by' => $user->id,
            'approved_at' => now(),
            'override_by' => $user->id,
            'override_reason' => 'Проверено редактором',
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(PlannedPostStatus::Approved, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Ready, $contentPlan->fresh()->status);
        $this->assertDatabaseMissing('moderation_actions', [
            'subject_id' => $plannedPost->id,
            'action' => 'safety_net_skip_post',
        ]);
    }

    public function test_plan_is_skipped_when_refresh_has_no_safe_candidates(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::CandidateReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => [
                CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String(),
            ],
        ]);
        $this->createCandidateWithSource(
            $contentPlan,
            riskFlags: ['unreliable_content'],
        );

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(ContentPlanStatus::Skipped, $contentPlan->fresh()->status);
        $this->assertNotNull($contentPlan->fresh()->safety_net_completed_at);
        $this->assertFalse($contentPlan->plannedPosts()->exists());
        Queue::assertNothingPushed();
    }

    public function test_failed_media_preparation_skips_the_post_instead_of_bypassing_the_error(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 00:10:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        Destination::factory()->create([
            'publication_id' => $publication->id,
            'is_active' => true,
        ]);
        $slot = CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::FinalReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => [$slot],
        ]);
        $candidate = $this->createCandidateWithSource($contentPlan);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => $slot,
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
            'ai_review_status' => 'passed',
        ]);
        MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'path' => null,
            'failed_at' => now(),
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(PlannedPostStatus::Cancelled, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Skipped, $contentPlan->fresh()->status);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $plannedPost->id,
            'action' => 'safety_net_skip_post',
            'reason' => 'media_preparation_failed',
        ]);
        $this->assertFalse($plannedPost->deliveries()->exists());
    }

    public function test_post_without_a_future_slot_is_skipped_instead_of_moving_to_another_day(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 10:00:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create();
        Destination::factory()->create([
            'publication_id' => $publication->id,
            'is_active' => true,
        ]);
        $pastSlot = CarbonImmutable::parse('2026-07-26 09:00:00', 'Europe/Moscow')->utc()->toIso8601String();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::FinalReview,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now(),
            'safety_net_refreshed_at' => now(),
            'slot_schedule' => [$pastSlot],
        ]);
        $candidate = $this->createCandidateWithSource($contentPlan);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => $pastSlot,
            'status' => PlannedPostStatus::FinalReview,
            'risk_flags' => [],
            'ai_review_status' => 'passed',
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(PlannedPostStatus::Cancelled, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Skipped, $contentPlan->fresh()->status);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $plannedPost->id,
            'action' => 'safety_net_skip_post',
            'reason' => 'no_future_publication_slot',
        ]);
    }

    public function test_unfinished_posts_are_expired_after_the_publication_window(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-07-26 23:01:00', 'Europe/Moscow'));
        $publication = Publication::factory()->create([
            'publish_window_end' => '23:00',
        ]);
        $slot = CarbonImmutable::parse('2026-07-26 22:00:00', 'Europe/Moscow')->utc()->toIso8601String();
        $contentPlan = ContentPlan::factory()->create([
            'publication_id' => $publication->id,
            'plan_date' => '2026-07-26',
            'status' => ContentPlanStatus::Rewriting,
            'generated_at' => now()->subHour(),
            'safety_net_started_at' => now()->subHours(20),
            'safety_net_refreshed_at' => now()->subHours(20),
            'slot_schedule' => [$slot],
        ]);
        $candidate = $this->createCandidateWithSource($contentPlan);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
            'scheduled_at' => $slot,
            'status' => PlannedPostStatus::Rewriting,
        ]);

        app(AdvanceContentPlanSafetyNet::class)->handle($publication);

        $this->assertSame(PlannedPostStatus::Cancelled, $plannedPost->fresh()->status);
        $this->assertSame(ContentPlanStatus::Skipped, $contentPlan->fresh()->status);
        $this->assertDatabaseHas('moderation_actions', [
            'subject_id' => $plannedPost->id,
            'action' => 'safety_net_skip_post',
            'reason' => 'publication_window_ended',
        ]);
    }

    /**
     * @param  list<string>  $riskFlags
     */
    private function createCandidateWithSource(
        ContentPlan $contentPlan,
        float $score = 90,
        array $riskFlags = [],
    ): StoryCandidate {
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'score' => $score,
            'risk_flags' => $riskFlags,
            'status' => CandidateStatus::Pending,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);

        return $candidate;
    }
}
