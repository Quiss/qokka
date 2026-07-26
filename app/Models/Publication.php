<?php

namespace App\Models;

use App\PublicationSignatureMode;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $source_group_id
 * @property string $name
 * @property string $slug
 * @property string $language
 * @property string $timezone
 * @property string $tone_prompt
 * @property string|null $selection_prompt
 * @property list<string>|null $tone_examples
 * @property list<string>|null $forbidden_phrases
 * @property array<string, mixed>|null $content_filters
 * @property string|null $analysis_model
 * @property string|null $rewrite_model
 * @property string $planning_time
 * @property string $publish_window_start
 * @property string $publish_window_end
 * @property int $min_interval_minutes
 * @property int $max_interval_minutes
 * @property numeric-string $reserve_multiplier
 * @property int $media_caption_limit
 * @property bool $show_source_attribution
 * @property PublicationSignatureMode $signature_mode
 * @property string|null $signature_label
 * @property bool $is_active
 * @property-read SourceGroup $sourceGroup
 * @property-read Collection<int, Destination> $destinations
 * @property-read Destination|null $destination
 */
#[Fillable(['source_group_id', 'name', 'slug', 'language', 'timezone', 'tone_prompt', 'selection_prompt', 'tone_examples', 'forbidden_phrases', 'content_filters', 'analysis_model', 'rewrite_model', 'planning_time', 'publish_window_start', 'publish_window_end', 'min_interval_minutes', 'max_interval_minutes', 'reserve_multiplier', 'media_caption_limit', 'show_source_attribution', 'signature_mode', 'signature_label', 'is_active'])]
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    protected $attributes = [
        'language' => 'ru',
        'timezone' => 'Europe/Moscow',
        'planning_time' => '18:00',
        'publish_window_start' => '09:00',
        'publish_window_end' => '23:00',
        'min_interval_minutes' => 90,
        'max_interval_minutes' => 180,
        'reserve_multiplier' => 1.5,
        'media_caption_limit' => 900,
        'show_source_attribution' => false,
        'signature_mode' => 'none',
        'is_active' => true,
    ];

    /** @return BelongsTo<SourceGroup, $this> */
    public function sourceGroup(): BelongsTo
    {
        return $this->belongsTo(SourceGroup::class);
    }

    /** @return HasMany<Destination, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    /** @return HasOne<Destination, $this> */
    public function destination(): HasOne
    {
        return $this->hasOne(Destination::class);
    }

    /** @return HasMany<ContentPlan, $this> */
    public function contentPlans(): HasMany
    {
        return $this->hasMany(ContentPlan::class);
    }

    public function signatureMarkdown(?Destination $destination = null): ?string
    {
        if ($this->signature_mode === PublicationSignatureMode::None) {
            return null;
        }

        $destination ??= $this->relationLoaded('destination')
            ? $this->destination
            : $this->destination()->first();
        $username = trim((string) $destination?->external_id);

        if (! Str::startsWith($username, '@')) {
            return null;
        }

        if ($this->signature_mode === PublicationSignatureMode::Username) {
            return $username;
        }

        $label = filled($this->signature_label) ? $this->signature_label : $this->name;
        $label = str_replace(['[', ']'], ['\\[', '\\]'], $label);
        $baseUrl = rtrim((string) config('services.telegram.messenger_base_url', 'https://t.me'), '/');

        return "[{$label}]({$baseUrl}/".ltrim($username, '@').')';
    }

    protected function casts(): array
    {
        return [
            'tone_examples' => 'array',
            'forbidden_phrases' => 'array',
            'content_filters' => 'array',
            'reserve_multiplier' => 'decimal:2',
            'show_source_attribution' => 'boolean',
            'signature_mode' => PublicationSignatureMode::class,
            'is_active' => 'boolean',
        ];
    }
}
