<?php

namespace Database\Factories;

use App\Models\Publication;
use App\Models\SourceGroup;
use App\PublicationSignatureMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_group_id' => SourceGroup::factory(),
            'name' => fake()->unique()->company(),
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']).'-'.fake()->unique()->numerify('###'),
            'language' => 'ru',
            'timezone' => 'Europe/Moscow',
            'tone_prompt' => 'Пиши динамично, ясно и без канцелярита.',
            'content_filters' => [],
            'planning_time' => '18:00',
            'publish_window_start' => '09:00',
            'publish_window_end' => '23:00',
            'min_interval_minutes' => 90,
            'max_interval_minutes' => 180,
            'reserve_multiplier' => 1.5,
            'media_caption_limit' => 900,
            'show_source_attribution' => false,
            'signature_mode' => PublicationSignatureMode::None,
            'is_active' => true,
        ];
    }
}
