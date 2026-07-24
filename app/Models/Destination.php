<?php

namespace App\Models;

use App\DestinationPlatform;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $publication_id
 * @property DestinationPlatform $platform
 * @property string $external_id
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property-read Publication $publication
 */
#[Fillable(['publication_id', 'platform', 'name', 'external_id', 'settings', 'is_active', 'last_verified_at'])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    protected $attributes = ['platform' => 'telegram', 'is_active' => true];

    /** @return BelongsTo<Publication, $this> */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    protected function casts(): array
    {
        return [
            'platform' => DestinationPlatform::class,
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
