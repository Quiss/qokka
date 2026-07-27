<?php

namespace App\Models;

use App\PlannedPostStatus;
use Database\Factories\PlannedPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $content_plan_id
 * @property int $story_candidate_id
 * @property string|null $text
 * @property string|null $original_ai_text
 * @property int $rewrite_generation
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $published_at
 * @property PlannedPostStatus $status
 * @property list<string>|null $risk_flags
 * @property string|null $ai_review_status
 * @property-read ContentPlan $contentPlan
 * @property-read StoryCandidate $storyCandidate
 * @property-read Collection<int, Delivery> $deliveries
 * @property-read Collection<int, MediaAsset> $mediaAssets
 * @property-read Collection<int, PlannedPostRevision> $revisions
 */
#[Fillable(['content_plan_id', 'story_candidate_id', 'replaces_planned_post_id', 'text', 'original_ai_text', 'rewrite_generation', 'scheduled_at', 'status', 'risk_flags', 'ai_review_status', 'approved_by', 'approved_at', 'override_by', 'override_reason', 'published_at', 'failure_reason', 'failed_at'])]
class PlannedPost extends Model
{
    /** @use HasFactory<PlannedPostFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'rewriting', 'rewrite_generation' => 0];

    /** @return BelongsTo<ContentPlan, $this> */
    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    /** @return BelongsTo<StoryCandidate, $this> */
    public function storyCandidate(): BelongsTo
    {
        return $this->belongsTo(StoryCandidate::class);
    }

    /** @return BelongsTo<PlannedPost, $this> */
    public function replacesPlannedPost(): BelongsTo
    {
        return $this->belongsTo(PlannedPost::class, 'replaces_planned_post_id');
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** @return MorphMany<MediaAsset, $this> */
    public function mediaAssets(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'mediable')->orderBy('sort_order');
    }

    /** @return HasMany<PlannedPostRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PlannedPostRevision::class)->orderByDesc('version');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publicationTimezone(): string
    {
        return $this->contentPlan->publication->timezone ?: (string) config('app.timezone');
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => PlannedPostStatus::class,
            'risk_flags' => 'array',
            'rewrite_generation' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
