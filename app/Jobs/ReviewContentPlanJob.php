<?php

namespace App\Jobs;

use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\Contracts\FallbackContentIntelligence;
use App\Jobs\Concerns\UsesFallbackModelOnFinalAttempt;
use App\Models\ContentPlan;
use App\PlannedPostStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReviewContentPlanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use UsesFallbackModelOnFinalAttempt;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public int $timeout = 330;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    /** @param list<int>|null $focusPlannedPostIds */
    public function __construct(
        public readonly int $contentPlanId,
        public readonly ?array $focusPlannedPostIds = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->contentPlanId.':'.implode(',', $this->focusPlannedPostIds ?? ['all']);
    }

    /**
     * Execute the job.
     */
    public function handle(ContentIntelligence $contentIntelligence): void
    {
        $contentPlan = ContentPlan::query()
            ->with(['plannedPosts' => fn ($query) => $query->where('status', '!=', PlannedPostStatus::Cancelled)])
            ->findOrFail($this->contentPlanId);
        $result = $this->shouldUseFallbackModel() && $contentIntelligence instanceof FallbackContentIntelligence
            ? $contentIntelligence->reviewPlanWithFallback($contentPlan)
            : $contentIntelligence->reviewPlan($contentPlan);
        $duplicateIds = collect($result['duplicate_groups'] ?? [])->flatten()->map(fn ($id): int => (int) $id)->flip();

        $reviews = collect($result['items'])->keyBy('planned_post_id');

        $postsToUpdate = $this->focusPlannedPostIds === null
            ? $contentPlan->plannedPosts
            : $contentPlan->plannedPosts->whereIn('id', $this->focusPlannedPostIds);
        $postsToUpdate = $postsToUpdate->whereIn('status', PlannedPostStatus::reviewableCases());

        foreach ($postsToUpdate as $post) {
            $review = $reviews->get($post->id);
            $riskFlags = array_values(array_unique(array_merge(
                $post->risk_flags ?? [],
                is_array($review) ? ($review['risk_flags'] ?? []) : ['ai_review_missing'],
                $duplicateIds->has($post->id) ? ['duplicate_in_daily_plan'] : [],
            )));
            $post->update([
                'risk_flags' => $riskFlags,
                'ai_review_status' => $riskFlags === [] ? 'passed' : 'blocked',
                'status' => $riskFlags === [] ? PlannedPostStatus::FinalReview : PlannedPostStatus::Blocked,
            ]);
        }

        $hasRewritingPosts = $contentPlan->plannedPosts()
            ->where('status', PlannedPostStatus::Rewriting)
            ->exists();
        $contentPlan->update([
            'status' => $hasRewritingPosts ? ContentPlanStatus::Rewriting : ContentPlanStatus::FinalReview,
            'ai_reviewed_at' => now(),
            'failure_reason' => null,
            'failed_at' => null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        ContentPlan::query()->whereKey($this->contentPlanId)->update([
            'status' => ContentPlanStatus::Failed,
            'failure_reason' => $exception?->getMessage() ?? 'Неизвестная ошибка проверки плана.',
            'failed_at' => now(),
        ]);
    }
}
