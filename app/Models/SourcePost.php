<?php

namespace App\Models;

use Database\Factories\SourcePostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $source_channel_id
 * @property string $canonical_key
 * @property string|null $text
 * @property array<string, mixed>|null $metrics
 * @property array<string, mixed>|null $metadata
 * @property int $views
 * @property int $forwards
 * @property int $reactions
 * @property int $comments
 * @property Carbon $posted_at
 * @property Carbon|null $edited_at
 * @property-read SourceChannel $sourceChannel
 * @property-read Collection<int, SourceMessage> $messages
 * @property-read Collection<int, MediaAsset> $mediaAssets
 */
#[Fillable(['source_channel_id', 'canonical_key', 'telegram_grouped_id', 'text', 'normalized_text', 'source_url', 'metrics', 'views', 'forwards', 'reactions', 'comments', 'metadata', 'status', 'posted_at', 'edited_at', 'deleted_at'])]
class SourcePost extends Model
{
    /** @use HasFactory<SourcePostFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'active'];

    /** @return BelongsTo<SourceChannel, $this> */
    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(SourceChannel::class);
    }

    /** @return HasMany<SourceMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SourceMessage::class);
    }

    /** @return MorphMany<MediaAsset, $this> */
    public function mediaAssets(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'mediable')->orderBy('sort_order');
    }

    /** @return BelongsToMany<StoryCandidate, $this> */
    public function storyCandidates(): BelongsToMany
    {
        return $this->belongsToMany(StoryCandidate::class)->withPivot('is_primary');
    }

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'views' => 'integer',
            'forwards' => 'integer',
            'reactions' => 'integer',
            'comments' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
