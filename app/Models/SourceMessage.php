<?php

namespace App\Models;

use Database\Factories\SourceMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $source_post_id
 * @property int $source_id
 * @property int|null $telegram_account_id
 * @property int $external_message_id
 * @property string|null $text
 * @property array<string, mixed>|null $metrics
 * @property int $views
 * @property int $forwards
 * @property int $reactions
 * @property int $comments
 * @property array<string, mixed>|null $raw_payload
 * @property-read SourcePost $sourcePost
 * @property-read TelegramAccount|null $telegramAccount
 * @property-read Collection<int, MediaAsset> $sourceMediaAssets
 */
#[Fillable(['source_post_id', 'source_id', 'telegram_account_id', 'external_message_id', 'telegram_grouped_id', 'text', 'entities', 'metrics', 'views', 'forwards', 'reactions', 'comments', 'raw_payload', 'posted_at', 'edited_at', 'deleted_at'])]
class SourceMessage extends Model
{
    /** @use HasFactory<SourceMessageFactory> */
    use HasFactory;

    /** @return BelongsTo<SourcePost, $this> */
    public function sourcePost(): BelongsTo
    {
        return $this->belongsTo(SourcePost::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<TelegramAccount, $this> */
    public function telegramAccount(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class);
    }

    /** @return MorphMany<MediaAsset, $this> */
    public function mediaAssets(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'mediable')->orderBy('sort_order');
    }

    /** @return HasMany<MediaAsset, $this> */
    public function sourceMediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    protected function casts(): array
    {
        return [
            'entities' => 'array',
            'metrics' => 'array',
            'views' => 'integer',
            'forwards' => 'integer',
            'reactions' => 'integer',
            'comments' => 'integer',
            'raw_payload' => 'array',
            'posted_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
