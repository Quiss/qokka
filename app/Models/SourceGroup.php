<?php

namespace App\Models;

use Database\Factories\SourceGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class SourceGroup extends Model
{
    /** @use HasFactory<SourceGroupFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    /** @return BelongsToMany<Source, $this> */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'source_group_source');
    }

    /** @return HasMany<Publication, $this> */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
