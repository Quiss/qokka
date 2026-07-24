<?php

namespace App\Jobs;

use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\Models\PlannedPost;
use App\Models\PlannedPostRevision;
use App\PlannedPostStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RewritePlannedPostJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 330;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $plannedPostId,
        public readonly int $rewriteGeneration = 0,
        public readonly ?string $instruction = null,
        public readonly ?int $requestedById = null,
        public readonly bool $focusedReview = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->plannedPostId.':'.$this->rewriteGeneration;
    }

    public function handle(ContentIntelligence $contentIntelligence): void
    {
        $plannedPost = PlannedPost::query()
            ->with('storyCandidate.sourcePosts')
            ->findOrFail($this->plannedPostId);

        if ($plannedPost->status !== PlannedPostStatus::Rewriting || $plannedPost->rewrite_generation !== $this->rewriteGeneration) {
            return;
        }

        $result = $contentIntelligence->rewrite($plannedPost, $this->instruction);
        $applied = DB::transaction(function () use ($result): ?PlannedPost {
            $lockedPost = PlannedPost::query()
                ->with('storyCandidate')
                ->lockForUpdate()
                ->findOrFail($this->plannedPostId);

            if ($lockedPost->status !== PlannedPostStatus::Rewriting || $lockedPost->rewrite_generation !== $this->rewriteGeneration) {
                return null;
            }

            $version = ((int) PlannedPostRevision::query()
                ->whereBelongsTo($lockedPost)
                ->max('version')) + 1;
            $riskFlags = array_values(array_unique(array_merge(
                $lockedPost->storyCandidate->risk_flags ?? [],
                $result['risk_flags'] ?? [],
            )));

            $lockedPost->revisions()->create([
                'version' => $version,
                'text' => $result['text'],
                'risk_flags' => $riskFlags,
                'instruction' => $this->instruction,
                'requested_by' => $this->requestedById,
                'ai_run_id' => $result['ai_run_id'] ?? null,
            ]);
            $lockedPost->update([
                'text' => $result['text'],
                'original_ai_text' => $lockedPost->original_ai_text ?: $result['text'],
                'risk_flags' => $riskFlags,
                'status' => PlannedPostStatus::FinalReview,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            return $lockedPost->fresh();
        });

        if ($applied === null) {
            return;
        }

        if ($this->focusedReview) {
            ReviewContentPlanJob::dispatch($applied->content_plan_id, [$applied->id])->onQueue('ai');

            return;
        }

        if (! $applied->contentPlan->plannedPosts()->where('status', PlannedPostStatus::Rewriting)->exists()) {
            ReviewContentPlanJob::dispatch($applied->content_plan_id)->onQueue('ai');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = PlannedPost::query()->find($this->plannedPostId);

        if (
            $post === null
            || $post->status !== PlannedPostStatus::Rewriting
            || $post->rewrite_generation !== $this->rewriteGeneration
        ) {
            return;
        }

        $reason = $exception?->getMessage() ?? 'Неизвестная ошибка рерайта.';
        $post->update([
            'status' => PlannedPostStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);
        $post->contentPlan()->update([
            'status' => ContentPlanStatus::Failed,
            'failure_reason' => $reason,
            'failed_at' => now(),
        ]);
    }
}
