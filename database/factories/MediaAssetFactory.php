<?php

namespace Database\Factories;

use App\MediaType;
use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => fake()->uuid(),
            'type' => MediaType::Photo,
            'disk' => 'local',
            'path' => 'telegram/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(10_000, 5_000_000),
            'checksum' => fake()->sha256(),
            'sort_order' => 0,
            'metadata' => [],
            'downloaded_at' => now(),
        ];
    }
}
