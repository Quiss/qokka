<?php

namespace Tests\Feature;

use App\Actions\DeleteContentPlan;
use App\Filament\Resources\ContentPlans\Pages\ListContentPlans;
use App\MediaType;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\ModerationAction;
use App\Models\PlannedPost;
use App\Models\PlannedPostRevision;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DeleteContentPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_plan_deletion_cleans_related_records_and_unreferenced_media(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $plan = ContentPlan::factory()->create();
        $destination = Destination::factory()->create([
            'publication_id' => $plan->publication_id,
        ]);
        $sourcePost = SourcePost::factory()->create();
        $sourceMedia = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => 'telegram/shared.jpg',
        ]);
        $candidate = StoryCandidate::factory()->create([
            'content_plan_id' => $plan->id,
        ]);
        $candidate->sourcePosts()->attach($sourcePost, ['is_primary' => true]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $plan->id,
            'story_candidate_id' => $candidate->id,
        ]);
        $sharedMedia = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $sourceMedia->id,
            'path' => 'telegram/shared.jpg',
        ]);
        $uniqueMedia = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'type' => MediaType::Photo,
            'path' => 'telegram/plan-only.jpg',
        ]);
        $delivery = Delivery::factory()->create([
            'planned_post_id' => $plannedPost->id,
            'destination_id' => $destination->id,
        ]);
        $revision = PlannedPostRevision::factory()->create([
            'planned_post_id' => $plannedPost->id,
        ]);
        $aiRuns = collect([
            AiRun::factory()->create(['subject_type' => ContentPlan::class, 'subject_id' => $plan->id]),
            AiRun::factory()->create(['subject_type' => StoryCandidate::class, 'subject_id' => $candidate->id]),
            AiRun::factory()->create(['subject_type' => PlannedPost::class, 'subject_id' => $plannedPost->id]),
        ]);
        $moderationActions = collect([
            ModerationAction::factory()->create([
                'user_id' => $user->id,
                'subject_type' => ContentPlan::class,
                'subject_id' => $plan->id,
            ]),
            ModerationAction::factory()->create([
                'user_id' => $user->id,
                'subject_type' => StoryCandidate::class,
                'subject_id' => $candidate->id,
            ]),
            ModerationAction::factory()->create([
                'user_id' => $user->id,
                'subject_type' => PlannedPost::class,
                'subject_id' => $plannedPost->id,
            ]),
        ]);
        Storage::disk('local')->put('telegram/shared.jpg', 'shared');
        Storage::disk('local')->put('telegram/plan-only.jpg', 'unique');

        $deleted = app(DeleteContentPlan::class)->handle($plan);

        $this->assertTrue($deleted);
        $this->assertModelMissing($plan);
        $this->assertModelMissing($candidate);
        $this->assertModelMissing($plannedPost);
        $this->assertModelMissing($sharedMedia);
        $this->assertModelMissing($uniqueMedia);
        $this->assertModelMissing($delivery);
        $this->assertModelMissing($revision);
        $aiRuns->each(fn (AiRun $aiRun) => $this->assertModelMissing($aiRun));
        $moderationActions->each(fn (ModerationAction $moderationAction) => $this->assertModelMissing($moderationAction));
        $this->assertModelExists($sourcePost);
        $this->assertModelExists($sourceMedia);
        Storage::disk('local')->assertExists('telegram/shared.jpg');
        Storage::disk('local')->assertMissing('telegram/plan-only.jpg');
    }

    public function test_admin_can_delete_a_content_plan_from_the_compact_table(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = ContentPlan::factory()->create();
        $this->actingAs($user);

        Livewire::test(ListContentPlans::class)
            ->assertCanSeeTableRecords([$plan])
            ->assertActionVisible(TestAction::make('delete')->table($plan))
            ->mountAction(TestAction::make('delete')->table($plan))
            ->assertActionMounted(TestAction::make('delete')->table($plan))
            ->callMountedAction()
            ->assertNotified('Контент-план удалён');

        $this->assertModelMissing($plan);
    }
}
