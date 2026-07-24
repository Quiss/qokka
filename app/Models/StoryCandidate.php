<?php

namespace App\Models;

use App\CandidateStatus;
use Database\Factories\StoryCandidateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $content_plan_id
 * @property string $title
 * @property string|null $summary
 * @property numeric-string $score
 * @property array<string, mixed>|null $score_breakdown
 * @property string|null $ai_reason
 * @property list<string>|null $risk_flags
 * @property CandidateStatus $status
 * @property-read ContentPlan $contentPlan
 * @property-read Collection<int, SourcePost> $sourcePosts
 */
#[Fillable(['content_plan_id', 'title', 'summary', 'score', 'score_breakdown', 'ai_reason', 'risk_flags', 'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at'])]
class StoryCandidate extends Model
{
    /** @use HasFactory<StoryCandidateFactory> */
    use HasFactory;

    protected $attributes = ['score' => 0, 'status' => 'pending'];

    /** @return BelongsTo<ContentPlan, $this> */
    public function contentPlan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class);
    }

    /** @return BelongsToMany<SourcePost, $this> */
    public function sourcePosts(): BelongsToMany
    {
        return $this->belongsToMany(SourcePost::class)->withPivot('is_primary');
    }

    /** @return HasOne<PlannedPost, $this> */
    public function plannedPost(): HasOne
    {
        return $this->hasOne(PlannedPost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected function casts(): array
    {
        return [
            'score' => 'decimal:3',
            'score_breakdown' => 'array',
            'risk_flags' => 'array',
            'status' => CandidateStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
