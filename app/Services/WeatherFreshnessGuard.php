<?php

namespace App\Services;

use App\Models\SourcePost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class WeatherFreshnessGuard
{
    /** @var array<string, int> */
    private const MONTHS = [
        'января' => 1,
        'февраля' => 2,
        'марта' => 3,
        'апреля' => 4,
        'мая' => 5,
        'июня' => 6,
        'июля' => 7,
        'августа' => 8,
        'сентября' => 9,
        'октября' => 10,
        'ноября' => 11,
        'декабря' => 12,
    ];

    public function isStaleForPlan(
        SourcePost $sourcePost,
        CarbonInterface $planDate,
        string $timezone,
    ): bool {
        $text = Str::lower(Str::squish($sourcePost->text ?? ''));

        if ($text === '' || ! $this->isWeatherForecast($text)) {
            return false;
        }

        $sourceDate = CarbonImmutable::instance($sourcePost->posted_at)
            ->setTimezone($timezone)
            ->startOfDay();
        $targetDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $planDate->format('Y-m-d'),
            $timezone,
        );
        $relevanceDates = [
            ...$this->relativeDates($text, $sourceDate),
            ...$this->namedDates($text, $sourceDate),
            ...$this->numericDates($text, $sourceDate),
        ];
        $latestRelevanceDate = null;

        foreach ($relevanceDates as $relevanceDate) {
            if ($latestRelevanceDate === null || $relevanceDate->isAfter($latestRelevanceDate)) {
                $latestRelevanceDate = $relevanceDate;
            }
        }

        return $latestRelevanceDate?->isBefore($targetDate) ?? false;
    }

    private function isWeatherForecast(string $text): bool
    {
        if (Str::contains($text, ['погод', 'синоптик', 'метеоролог'])) {
            return true;
        }

        $hasWeatherTerms = Str::contains($text, [
            'температур', 'градус', 'осадк', 'дожд', 'ливн', 'гроз',
            'снег', 'голол', 'ветер', 'шторм', 'жар', 'мороз',
        ]);
        $hasForecastTerms = Str::contains($text, [
            'прогноз', 'ожида', 'обеща', 'будет', 'накро',
            'потепл', 'похолод', 'придёт', 'придет',
        ]);

        return $hasWeatherTerms && $hasForecastTerms;
    }

    /** @return list<CarbonImmutable> */
    private function relativeDates(string $text, CarbonImmutable $sourceDate): array
    {
        $dates = [];

        if (preg_match('/\bсегодня(?:шн\p{L}*)?\b/ui', $text) === 1) {
            $dates[] = $sourceDate;
        }

        if (preg_match('/\bзавтра(?:шн\p{L}*)?\b/ui', $text) === 1) {
            $dates[] = $sourceDate->addDay();
        }

        if (preg_match('/\bпослезавтра\b/ui', $text) === 1) {
            $dates[] = $sourceDate->addDays(2);
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function namedDates(string $text, CarbonImmutable $sourceDate): array
    {
        preg_match_all(
            '/(?<!\d)([0-3]?\d)(?:\s*[-–—]\s*([0-3]?\d))?\s+('
                .implode('|', array_keys(self::MONTHS))
                .')(?:\s+(\d{4}))?(?!\d)/ui',
            $text,
            $matches,
            PREG_SET_ORDER,
        );
        $dates = [];

        foreach ($matches as $match) {
            $day = (int) ($match[2] !== '' ? $match[2] : $match[1]);
            $month = self::MONTHS[Str::lower($match[3])] ?? null;

            if ($month === null) {
                continue;
            }

            $date = $this->resolveDate(
                $day,
                $month,
                ($match[4] ?? '') !== '' ? (int) $match[4] : null,
                $sourceDate,
            );

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function numericDates(string $text, CarbonImmutable $sourceDate): array
    {
        preg_match_all(
            '/(?<![\d.\/-])([0-3]?\d)[.\/-]([01]?\d)(?:[.\/-](\d{2}|\d{4}))?(?![\d.\/-])/',
            $text,
            $matches,
            PREG_SET_ORDER,
        );
        $dates = [];

        foreach ($matches as $match) {
            $year = ($match[3] ?? '') !== '' ? (int) $match[3] : null;

            if ($year !== null && $year < 100) {
                $year += 2000;
            }

            $date = $this->resolveDate(
                (int) $match[1],
                (int) $match[2],
                $year,
                $sourceDate,
            );

            if ($date !== null) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    private function resolveDate(
        int $day,
        int $month,
        ?int $year,
        CarbonImmutable $sourceDate,
    ): ?CarbonImmutable {
        if ($year !== null) {
            return $this->createValidDate($year, $month, $day, $sourceDate->timezone->getName());
        }

        $nearestDate = null;
        $nearestDistance = null;

        foreach ([$sourceDate->year - 1, $sourceDate->year, $sourceDate->year + 1] as $candidateYear) {
            $candidate = $this->createValidDate(
                $candidateYear,
                $month,
                $day,
                $sourceDate->timezone->getName(),
            );

            if ($candidate === null) {
                continue;
            }

            $distance = abs($sourceDate->diffInDays($candidate, false));

            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearestDate = $candidate;
                $nearestDistance = $distance;
            }
        }

        return $nearestDate;
    }

    private function createValidDate(int $year, int $month, int $day, string $timezone): ?CarbonImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, $timezone);
    }
}
