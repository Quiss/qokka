<?php

namespace Tests\Unit;

use App\Models\SourcePost;
use App\Services\WeatherFreshnessGuard;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WeatherFreshnessGuardTest extends TestCase
{
    public function test_weather_for_an_earlier_named_date_is_stale(): void
    {
        $sourcePost = $this->sourcePost(
            'Погода 26 июля: в Петербурге ожидаются дожди и +18 градусов.',
            '2026-07-26 18:00:00',
        );

        $this->assertTrue($this->guard()->isStaleForPlan(
            $sourcePost,
            CarbonImmutable::parse('2026-07-27', 'Europe/Moscow'),
            'Europe/Moscow',
        ));
    }

    public function test_weather_for_the_plan_date_or_a_date_range_covering_it_is_current(): void
    {
        $planDate = CarbonImmutable::parse('2026-07-27', 'Europe/Moscow');

        $this->assertFalse($this->guard()->isStaleForPlan(
            $this->sourcePost('Погода 27 июля: днём до +22 градусов.', '2026-07-26 18:00:00'),
            $planDate,
            'Europe/Moscow',
        ));
        $this->assertFalse($this->guard()->isStaleForPlan(
            $this->sourcePost('Прогноз погоды на 26–27 июля.', '2026-07-26 18:00:00'),
            $planDate,
            'Europe/Moscow',
        ));
    }

    public function test_relative_weather_dates_are_resolved_from_the_source_timestamp(): void
    {
        $planDate = CarbonImmutable::parse('2026-07-27', 'Europe/Moscow');

        $this->assertTrue($this->guard()->isStaleForPlan(
            $this->sourcePost('Сегодняшняя погода будет дождливой.', '2026-07-26 18:00:00'),
            $planDate,
            'Europe/Moscow',
        ));
        $this->assertFalse($this->guard()->isStaleForPlan(
            $this->sourcePost('Завтра погода будет солнечной.', '2026-07-26 18:00:00'),
            $planDate,
            'Europe/Moscow',
        ));
    }

    public function test_non_weather_news_with_a_historical_date_is_not_filtered(): void
    {
        $sourcePost = $this->sourcePost(
            'Город вспоминает открытие станции метро 26 июля.',
            '2026-07-26 18:00:00',
        );

        $this->assertFalse($this->guard()->isStaleForPlan(
            $sourcePost,
            CarbonImmutable::parse('2026-07-27', 'Europe/Moscow'),
            'Europe/Moscow',
        ));
    }

    private function guard(): WeatherFreshnessGuard
    {
        return new WeatherFreshnessGuard;
    }

    private function sourcePost(string $text, string $postedAt): SourcePost
    {
        return new SourcePost([
            'text' => $text,
            'posted_at' => CarbonImmutable::parse($postedAt, 'Europe/Moscow'),
        ]);
    }
}
