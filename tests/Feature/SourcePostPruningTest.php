<?php

namespace Tests\Feature;

use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\SourceChannel;
use App\Models\SourceMessage;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SourcePostPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_source_posts_older_than_fourteen_days(): void
    {
        $this->travelTo('2026-07-24 12:00:00');
        Storage::fake('local');
        $channel = SourceChannel::factory()->create();
        $oldPost = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subDays(14)->subSecond(),
        ]);
        $boundaryPost = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subDays(14),
        ]);
        $recentPost = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subDays(13),
        ]);
        $message = SourceMessage::factory()->create([
            'source_post_id' => $oldPost->id,
            'source_channel_id' => $channel->id,
        ]);
        $asset = MediaAsset::factory()->for($oldPost, 'mediable')->create([
            'source_message_id' => $message->id,
            'path' => 'telegram/expired.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/expired-preview.jpg',
        ]);
        Storage::disk('local')->put($asset->path, 'source');
        Storage::disk('local')->put($asset->preview_path, 'preview');
        $contentPlan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $contentPlan->id]);
        $candidate->sourcePosts()->attach($oldPost, ['is_primary' => true]);

        $this->artisan('source-posts:prune')->assertSuccessful();

        $this->assertModelMissing($oldPost);
        $this->assertModelExists($boundaryPost);
        $this->assertModelExists($recentPost);
        $this->assertModelMissing($message);
        $this->assertModelMissing($asset);
        $this->assertDatabaseMissing('source_post_story_candidate', [
            'source_post_id' => $oldPost->id,
            'story_candidate_id' => $candidate->id,
        ]);
        Storage::disk('local')->assertMissing('telegram/expired.jpg');
        Storage::disk('local')->assertMissing('telegram/expired-preview.jpg');
    }

    public function test_pruning_keeps_files_still_used_by_a_planned_post(): void
    {
        $this->travelTo('2026-07-24 12:00:00');
        Storage::fake('local');
        $channel = SourceChannel::factory()->create();
        $sourcePost = SourcePost::factory()->create([
            'source_channel_id' => $channel->id,
            'posted_at' => now()->subDays(15),
        ]);
        $sourceAsset = MediaAsset::factory()->for($sourcePost, 'mediable')->create([
            'path' => 'telegram/shared.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/shared-preview.jpg',
        ]);
        $contentPlan = ContentPlan::factory()->create();
        $candidate = StoryCandidate::factory()->create(['content_plan_id' => $contentPlan->id]);
        $plannedPost = PlannedPost::factory()->create([
            'content_plan_id' => $contentPlan->id,
            'story_candidate_id' => $candidate->id,
        ]);
        $plannedAsset = MediaAsset::factory()->for($plannedPost, 'mediable')->create([
            'origin_media_asset_id' => $sourceAsset->id,
            'path' => 'telegram/shared.jpg',
            'preview_disk' => 'local',
            'preview_path' => 'telegram/shared-preview.jpg',
        ]);
        Storage::disk('local')->put('telegram/shared.jpg', 'shared');
        Storage::disk('local')->put('telegram/shared-preview.jpg', 'preview');

        $this->artisan('source-posts:prune')->assertSuccessful();

        $this->assertModelMissing($sourcePost);
        $this->assertModelExists($plannedAsset->fresh());
        $this->assertNull($plannedAsset->fresh()->origin_media_asset_id);
        Storage::disk('local')->assertExists('telegram/shared.jpg');
        Storage::disk('local')->assertExists('telegram/shared-preview.jpg');
    }

    public function test_command_rejects_an_invalid_retention_period(): void
    {
        $this->artisan('source-posts:prune', ['--days' => 0])->assertFailed();
    }
}
