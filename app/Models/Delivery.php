<?php

namespace App\Models;

use App\DeliveryStatus;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $planned_post_id
 * @property int $destination_id
 * @property DeliveryStatus $status
 * @property list<string>|null $external_message_ids
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property-read PlannedPost $plannedPost
 * @property-read Destination $destination
 */
#[Fillable(['planned_post_id', 'destination_id', 'status', 'external_message_ids', 'attempts', 'next_attempt_at', 'published_at', 'last_error', 'error_context', 'is_ambiguous'])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'is_ambiguous' => false];

    /** @return BelongsTo<PlannedPost, $this> */
    public function plannedPost(): BelongsTo
    {
        return $this->belongsTo(PlannedPost::class);
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'external_message_ids' => 'array',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
            'error_context' => 'array',
            'is_ambiguous' => 'boolean',
        ];
    }
}
