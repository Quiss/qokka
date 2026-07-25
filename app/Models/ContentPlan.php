<?php

namespace App\Models;

use App\ContentPlanStatus;
use Database\Factories\ContentPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $publication_id
 * @property Carbon $plan_date
 * @property ContentPlanStatus $status
 * @property list<string>|null $slot_schedule
 * @property int $candidate_target
 * @property Carbon|null $generated_at
 * @property Carbon|null $ai_reviewed_at
 * @property int $story_candidates_count
 * @property int $planned_posts_count
 * @property-read Publication $publication
 * @property-read Collection<int, StoryCandidate> $storyCandidates
 * @property-read Collection<int, PlannedPost> $plannedPosts
 */
#[Fillable(['publication_id', 'plan_date', 'status', 'slot_schedule', 'candidate_target', 'generated_at', 'ai_reviewed_at', 'ready_at', 'failure_reason', 'failed_at'])]
class ContentPlan extends Model
{
    /** @use HasFactory<ContentPlanFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'candidate_review', 'candidate_target' => 0];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return HasMany<StoryCandidate, $this> */
    public function storyCandidates(): HasMany
    {
        return $this->hasMany(StoryCandidate::class);
    }

    /** @return HasMany<PlannedPost, $this> */
    public function plannedPosts(): HasMany
    {
        return $this->hasMany(PlannedPost::class);
    }

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'status' => ContentPlanStatus::class,
            'slot_schedule' => 'array',
            'generated_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
            'ready_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
