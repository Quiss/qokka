<?php

namespace Tests\Feature;

use App\DeliveryStatus;
use App\Models\ContentPlan;
use App\Models\Delivery;
use App\Models\Destination;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\Source;
use App\Models\SourceMessage;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentStoragePruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_expired_source_content_and_terminal_planned_post_media(): void
    {
        $this->travelTo('2026-07-27 12:00:00');
        config(['channelbot.content.retention_days' => 14]);
        Storage::fake('local');

        $publication = Publication::factory()->create();
        $destination = Destination::factory()->for($publication)->create();
        $contentPlan = ContentPlan::factory()->for($publication)->create([
            'plan_date' => now()->subDays(15)->toDateString(),
        ]);

        $oldSourcePost = SourcePost::factory()->for(Source::factory())->create([
            'posted_at' => now()->subDays(14)->subSecond(),
        ]);
        $oldSourceMessage = SourceMessage::factory()->create([
            'source_post_id' => $oldSourcePost->id,
            'source_id' => $oldSourcePost->source_id,
        ]);
        $oldSourceAsset = MediaAsset::factory()->for($oldSourcePost, 'mediable')->create([
            'source_message_id' => $oldSourceMessage->id,
            'path' => 'telegram/shared.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/shared-preview.jpg',
        ]);

        $publishedCandidate = StoryCandidate::factory()->for($contentPlan)->create();
        $publishedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $publishedCandidate->id,
            'text' => 'Текст опубликованного поста остаётся в истории.',
            'status' => PlannedPostStatus::Published,
            'scheduled_at' => now()->subDays(15),
            'published_at' => now()->subDays(14)->subSecond(),
        ]);
        $publishedAsset = MediaAsset::factory()->for($publishedPost, 'mediable')->create([
            'origin_media_asset_id' => $oldSourceAsset->id,
            'path' => 'telegram/shared.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/shared-preview.jpg',
        ]);
        $delivery = Delivery::factory()
            ->for($publishedPost, 'plannedPost')
            ->for($destination)
            ->create([
                'status' => DeliveryStatus::Published,
                'published_at' => $publishedPost->published_at,
                'external_message_ids' => ['100'],
            ]);

        $cancelledCandidate = StoryCandidate::factory()->for($contentPlan)->create();
        $cancelledPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $cancelledCandidate->id,
            'status' => PlannedPostStatus::Cancelled,
            'scheduled_at' => now()->subDays(14)->subSecond(),
        ]);
        $cancelledAsset = MediaAsset::factory()->for($cancelledPost, 'mediable')->create([
            'path' => 'telegram/cancelled.jpg',
        ]);

        $boundaryCandidate = StoryCandidate::factory()->for($contentPlan)->create();
        $boundaryPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $boundaryCandidate->id,
            'status' => PlannedPostStatus::Published,
            'published_at' => now()->subDays(14),
        ]);
        $boundaryAsset = MediaAsset::factory()->for($boundaryPost, 'mediable')->create([
            'path' => 'telegram/boundary.jpg',
        ]);

        $approvedCandidate = StoryCandidate::factory()->for($contentPlan)->create();
        $approvedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $approvedCandidate->id,
            'status' => PlannedPostStatus::Approved,
            'scheduled_at' => now()->subDays(15),
        ]);
        $approvedAsset = MediaAsset::factory()->for($approvedPost, 'mediable')->create([
            'path' => 'telegram/approved.jpg',
        ]);

        $recentSourcePost = SourcePost::factory()->create([
            'posted_at' => now()->subDays(13),
        ]);
        $recentSourceAsset = MediaAsset::factory()->for($recentSourcePost, 'mediable')->create([
            'path' => 'telegram/recent.jpg',
        ]);

        foreach ([
            'telegram/shared.jpg',
            'telegram/shared-preview.jpg',
            'telegram/cancelled.jpg',
            'telegram/boundary.jpg',
            'telegram/approved.jpg',
            'telegram/recent.jpg',
        ] as $path) {
            Storage::disk('local')->put($path, 'media');
        }

        $this->artisan('content-storage:prune')->assertSuccessful();

        $this->assertModelMissing($oldSourcePost);
        $this->assertModelMissing($oldSourceMessage);
        $this->assertModelMissing($oldSourceAsset);
        $this->assertModelMissing($publishedAsset);
        $this->assertModelMissing($cancelledAsset);
        $this->assertModelExists($contentPlan);
        $this->assertModelExists($publishedCandidate);
        $this->assertModelExists($publishedPost);
        $this->assertModelExists($delivery);
        $this->assertSame(
            'Текст опубликованного поста остаётся в истории.',
            $publishedPost->fresh()->text,
        );
        $this->assertSame(['100'], $delivery->fresh()->external_message_ids);

        $this->assertModelExists($boundaryAsset);
        $this->assertModelExists($approvedAsset);
        $this->assertModelExists($recentSourceAsset);
        Storage::disk('local')->assertMissing('telegram/shared.jpg');
        Storage::disk('local')->assertMissing('telegram/shared-preview.jpg');
        Storage::disk('local')->assertMissing('telegram/cancelled.jpg');
        Storage::disk('local')->assertExists('telegram/boundary.jpg');
        Storage::disk('local')->assertExists('telegram/approved.jpg');
        Storage::disk('local')->assertExists('telegram/recent.jpg');
    }

    public function test_command_rejects_an_invalid_retention_period(): void
    {
        $this->artisan('content-storage:prune', ['--days' => 0])->assertFailed();
    }

    public function test_combined_pruning_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('content-storage:prune')
            ->assertSuccessful();
    }
}
