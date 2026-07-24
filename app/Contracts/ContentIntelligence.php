<?php

namespace App\Contracts;

use App\Models\ContentPlan;
use App\Models\PlannedPost;
use App\Models\SourcePost;
use Illuminate\Support\Collection;

interface ContentIntelligence
{
    /**
     * @param  Collection<int, SourcePost>  $sourcePosts
     * @return array{clusters: list<array{source_post_ids: list<int>, title: string, summary: string, score: float|int, score_breakdown?: array<string, mixed>, selection_reason?: string, risk_flags?: list<string>, source_conflicts?: list<array{fact: string, variants: list<string>, source_post_ids: list<int>}>}>}
     */
    public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array;

    /** @return array{text: string, risk_flags?: list<string>, ai_run_id?: int} */
    public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array;

    /** @return array{items: list<array{planned_post_id: int, risk_flags?: list<string>, reason?: string}>, duplicate_groups?: list<list<int>>} */
    public function reviewPlan(ContentPlan $contentPlan): array;
}
