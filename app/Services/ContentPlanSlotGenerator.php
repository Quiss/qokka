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

    /**
     * @param  list<string>  $slots
     * @return list<string>
     */
    public function spreadAcrossWindow(array $slots, int $postCount): array
    {
        $slotCount = count($slots);
        $selectedCount = min(max(0, $postCount), $slotCount);

        if ($selectedCount === 0) {
            return [];
        }

        if ($selectedCount === $slotCount) {
            return $slots;
        }

        if ($selectedCount === 1) {
            return [$slots[intdiv($slotCount, 2)]];
        }

        $selectedSlots = [];
        $lastSlotIndex = $slotCount - 1;
        $lastPostIndex = $selectedCount - 1;

        for ($postIndex = 0; $postIndex < $selectedCount; $postIndex++) {
            $slotIndex = (int) round($postIndex * $lastSlotIndex / $lastPostIndex);
            $selectedSlots[] = $slots[$slotIndex];
        }

        return $selectedSlots;
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
