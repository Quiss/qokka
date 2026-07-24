<?php

namespace App\Services;

use App\Models\Publication;
use Carbon\CarbonImmutable;

class ContentPlanSlotGenerator
{
    /** @return list<string> */
    public function generate(Publication $publication, CarbonImmutable $planDate): array
    {
        $timezone = $publication->timezone;
        $date = $planDate->setTimezone($timezone)->format('Y-m-d');
        $cursor = CarbonImmutable::parse($date.' '.$publication->publish_window_start, $timezone);
        $end = CarbonImmutable::parse($date.' '.$publication->publish_window_end, $timezone);

        $slots = [];
        $index = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            $slots[] = $cursor->utc()->toIso8601String();
            $cursor = $cursor->addMinutes($this->intervalFor($publication, $date, $index));
            $index++;
        }

        return $slots;
    }

    private function intervalFor(Publication $publication, string $date, int $index): int
    {
        $minimum = (int) $publication->min_interval_minutes;
        $maximum = max($minimum, (int) $publication->max_interval_minutes);
        $range = $maximum - $minimum + 1;
        $hash = hash('sha256', "{$publication->slug}|{$date}|{$index}");
        $number = (int) hexdec(substr($hash, 0, 7));

        return $minimum + ($number % $range);
    }
}
