<?php

namespace App\Models;

use Database\Factories\PlannedPostRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $planned_post_id
 * @property int $version
 * @property string $text
 * @property list<string>|null $risk_flags
 * @property string|null $instruction
 */
#[Fillable(['planned_post_id', 'version', 'text', 'risk_flags', 'instruction', 'requested_by', 'ai_run_id'])]
class PlannedPostRevision extends Model
{
    /** @use HasFactory<PlannedPostRevisionFactory> */
    use HasFactory;

    /** @return BelongsTo<PlannedPost, $this> */
    public function plannedPost(): BelongsTo
    {
        return $this->belongsTo(PlannedPost::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<AiRun, $this> */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'risk_flags' => 'array',
        ];
    }
}
