<?php

namespace App\Actions;

use App\CandidateStatus;
use App\ContentPlanStatus;
use App\Contracts\ContentIntelligence;
use App\MediaType;
use App\Models\ContentPlan;
use App\Models\SourcePost;
use App\Services\ContentPlanSlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class GenerateCandidateBatch
{
    public function __construct(
        private readonly ContentIntelligence $contentIntelligence,
        private readonly ContentPlanSlotGenerator $slotGenerator,
    ) {}

    public function handle(
        ContentPlan $contentPlan,
        bool $append = false,
        int $lookbackHours = 24,
        ?int $targetOverride = null,
    ): ContentPlan {
        $contentPlan->loadMissing('publication.sourceGroup.sourceChannels');

        if (! $append && $contentPlan->plannedPosts()->exists()) {
            throw new LogicException('A content plan with planned posts cannot be regenerated.');
        }

        $publication = $contentPlan->publication;
        $planDate = CarbonImmutable::parse($contentPlan->plan_date, $publication->timezone);
        $slots = $this->slotGenerator->generate($publication, $planDate);
        $candidateTarget = (int) ceil(count($slots) * (float) $publication->reserve_multiplier);
        $preservedCandidateCount = $append
            ? 0
            : $contentPlan->storyCandidates()
                ->whereIn('status', [
                    CandidateStatus::Approved,
                    CandidateStatus::Reserve,
                    CandidateStatus::Selected,
                ])
                ->count();
        $requestedCandidates = $targetOverride ?? max(0, $candidateTarget - $preservedCandidateCount);
        $channelIds = $publication->sourceGroup->sourceChannels
            ->where('is_active', true)
            ->pluck('id');
        $posts = SourcePost::query()
            ->with(['sourceChannel', 'mediaAssets'])
            ->whereIn('source_channel_id', $channelIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereBetween('posted_at', [now()->subHours($lookbackHours), now()])
            ->whereDoesntHave(
                'storyCandidates',
                fn (Builder $candidateQuery): Builder => $candidateQuery
                    ->whereIn('status', [
                        CandidateStatus::Approved,
                        CandidateStatus::Reserve,
                        CandidateStatus::Selected,
                    ])
                    ->whereIn(
                        'content_plan_id',
                        ContentPlan::query()
                            ->select('id')
                            ->whereBelongsTo($publication),
                    ),
            )
            ->whereDoesntHave(
                'storyCandidates',
                fn ($candidateQuery) => $candidateQuery->where('content_plan_id', $contentPlan->id),
            )
            ->orderByDesc('posted_at')
            ->limit(120)
            ->get()
            ->filter(fn (SourcePost $post): bool => $this->passesDeterministicFilters($post, $publication->content_filters ?? []))
            ->values();

        if (! $append) {
            $contentPlan->update(['slot_schedule' => $slots, 'candidate_target' => $candidateTarget]);
        }

        if ($posts->isEmpty() || $requestedCandidates === 0) {
            DB::transaction(function () use ($contentPlan, $append): void {
                if (! $append) {
                    $contentPlan->storyCandidates()->where('status', CandidateStatus::Pending)->delete();
                }

                $contentPlan->update([
                    'status' => $append ? ContentPlanStatus::NeedsCandidates : ContentPlanStatus::CandidateReview,
                    'generated_at' => now(),
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);
            });

            return $contentPlan->fresh();
        }

        $result = $this->contentIntelligence->rankAndCluster($contentPlan, $posts);
        $allowedIds = $posts->pluck('id')->flip();
        $postsById = $posts->keyBy('id');
        $olderPostIds = $posts
            ->filter(fn (SourcePost $post): bool => $post->posted_at->lt(now()->subDay()))
            ->pluck('id')
            ->flip();

        DB::transaction(function () use ($contentPlan, $result, $allowedIds, $postsById, $olderPostIds, $requestedCandidates, $append): void {
            if (! $append) {
                $contentPlan->storyCandidates()->where('status', CandidateStatus::Pending)->delete();
            }

            $claimedSourceIds = collect();

            foreach (array_slice($result['clusters'], 0, $requestedCandidates) as $cluster) {
                $sourceIds = collect($cluster['source_post_ids'])
                    ->filter(fn (int $id): bool => $allowedIds->has($id))
                    ->unique()
                    ->reject(fn (int $id): bool => $claimedSourceIds->contains($id))
                    ->values();

                if ($sourceIds->isEmpty()) {
                    continue;
                }

                $primarySourceId = $sourceIds
                    ->sortByDesc(fn (int $id): float => $this->primarySourceScore($postsById->get($id)))
                    ->first();
                $riskFlags = array_values(array_unique(array_merge(
                    $cluster['risk_flags'] ?? [],
                    $sourceIds->contains(fn (int $id): bool => $olderPostIds->has($id)) ? ['older_than_24h'] : [],
                )));
                $candidate = $contentPlan->storyCandidates()->create([
                    'title' => Str::limit($cluster['title'], 255, ''),
                    'summary' => $cluster['summary'],
                    'score' => max(0, min(100, (float) $cluster['score'])),
                    'score_breakdown' => $cluster['score_breakdown'] ?? [],
                    'ai_reason' => $cluster['selection_reason'] ?? null,
                    'risk_flags' => $riskFlags,
                    'status' => CandidateStatus::Pending,
                ]);
                $candidate->sourcePosts()->sync($sourceIds->mapWithKeys(
                    fn (int $id): array => [$id => ['is_primary' => $id === $primarySourceId]],
                )->all());
                $claimedSourceIds = $claimedSourceIds->merge($sourceIds);
            }

            $contentPlan->update([
                'status' => $append ? ContentPlanStatus::NeedsCandidates : ContentPlanStatus::CandidateReview,
                'generated_at' => now(),
                'failure_reason' => null,
                'failed_at' => null,
            ]);
        });

        return $contentPlan->fresh(['storyCandidates.sourcePosts']);
    }

    private function primarySourceScore(?SourcePost $sourcePost): float
    {
        if ($sourcePost === null) {
            return 0;
        }

        $hasPublishableMedia = $sourcePost->mediaAssets
            ->whereIn('type', [MediaType::Photo, MediaType::Video])
            ->isNotEmpty();

        return ($hasPublishableMedia ? 1_000_000_000 : 0)
            + $sourcePost->views
            + ($sourcePost->reactions * 5)
            + ($sourcePost->forwards * 3)
            + ($sourcePost->comments * 2);
    }

    /** @param array<string, mixed> $filters */
    private function passesDeterministicFilters(SourcePost $post, array $filters): bool
    {
        if (blank($post->text) && $post->mediaAssets->isEmpty()) {
            return false;
        }

        $text = Str::lower(Str::squish($post->text ?? ''));
        $blocked = array_merge([
            'реклама', 'вакансия', 'розыгрыш', 'конкурс', 'промокод', 'ставки',
        ], $filters['blocked_phrases'] ?? []);

        return collect($blocked)->filter()->doesntContain(
            fn (string $phrase): bool => Str::contains($text, Str::lower($phrase)),
        );
    }
}
