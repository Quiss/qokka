<?php

namespace App\Services;

use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\PlannedPostStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RecentPublicationHistory
{
    private const TITLE_LIMIT = 160;

    private const SUMMARY_LIMIT = 180;

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     summary: string,
     *     at: string|null
     * }>
     */
    public function forPlan(ContentPlan $contentPlan): array
    {
        $contentPlan->loadMissing('publication');
        $cutoff = now()->subDays($this->lookbackDays());
        $timezone = $contentPlan->publication->timezone;

        return array_values(PlannedPost::query()
            ->select([
                'id',
                'content_plan_id',
                'story_candidate_id',
                'text',
                'scheduled_at',
                'status',
                'published_at',
            ])
            ->with('storyCandidate:id,title,summary')
            ->where('content_plan_id', '<>', $contentPlan->id)
            ->whereHas(
                'contentPlan',
                fn (Builder $query): Builder => $query
                    ->where('publication_id', $contentPlan->publication_id),
            )
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(function (Builder $query) use ($cutoff): void {
                        $query
                            ->where('status', PlannedPostStatus::Published)
                            ->where('published_at', '>=', $cutoff);
                    })
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query
                            ->whereIn('status', [
                                PlannedPostStatus::Approved,
                                PlannedPostStatus::Publishing,
                            ])
                            ->where('scheduled_at', '>=', $cutoff);
                    });
            })
            ->latest('id')
            ->limit($this->historyLimit())
            ->get()
            ->map(function (PlannedPost $plannedPost) use ($timezone): array {
                $summary = filled($plannedPost->storyCandidate->summary)
                    ? $plannedPost->storyCandidate->summary
                    : $plannedPost->text;
                $committedAt = $plannedPost->status === PlannedPostStatus::Published
                    ? $plannedPost->published_at
                    : $plannedPost->scheduled_at;

                return [
                    'id' => $plannedPost->id,
                    'title' => Str::limit($plannedPost->storyCandidate->title, self::TITLE_LIMIT, ''),
                    'summary' => Str::limit((string) $summary, self::SUMMARY_LIMIT, ''),
                    'at' => $committedAt
                        ?->setTimezone($timezone)
                        ->toIso8601String(),
                ];
            })
            ->all());
    }

    private function lookbackDays(): int
    {
        return max(1, (int) config('channelbot.content.duplicate_lookback_days', 14));
    }

    private function historyLimit(): int
    {
        return max(1, (int) config('channelbot.content.duplicate_history_limit', 80));
    }
}
