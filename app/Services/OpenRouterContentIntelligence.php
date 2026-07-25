<?php

namespace App\Services;

use App\AiOperation;
use App\Contracts\ContentIntelligence;
use App\MediaType;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\SourcePost;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type SourceConflict array{fact: string, variants: list<string>, source_post_ids: list<int>}
 * @phpstan-type RankingCluster array{
 *     source_post_ids: list<int>,
 *     title: string,
 *     summary: string,
 *     score: float|int,
 *     score_breakdown?: array<string, mixed>,
 *     selection_reason?: string,
 *     risk_flags?: list<string>,
 *     source_conflicts?: list<SourceConflict>
 * }
 * @phpstan-type RankingResult array{clusters: list<RankingCluster>}
 */
class OpenRouterContentIntelligence implements ContentIntelligence
{
    public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
    {
        $publication = $contentPlan->publication;
        $posts = $sourcePosts->map(fn ($post): array => [
            'id' => $post->id,
            'channel' => $post->sourceChannel->title,
            'channel_weight' => (float) $post->sourceChannel->weight,
            'text' => Str::limit($post->text ?? '', 1500),
            'metrics' => $post->metrics,
            'posted_at' => $post->posted_at->toIso8601String(),
            'preliminary_score' => $this->preliminaryScore($post->metrics ?? [], $post->posted_at, (float) $post->sourceChannel->weight),
            'media' => $post->mediaAssets
                ->map(fn (MediaAsset $asset): string => $asset->type->value)
                ->all(),
        ])->values()->all();

        $content = [[
            'type' => 'text',
            'text' => 'Сгруппируй сообщения об одном инфоповоде, оцени новостную ценность от 0 до 100 и верни лучшие кластеры. '
                .'Учитывай предварительный балл, свежесть, охват, реакции, вес источника, практическую ценность и оригинальность. '
                .'Если источники противоречат друг другу, перечисли конфликтующие факты в source_conflicts и добавь флаг source_conflict. '
                .'Не объединяй похожие, но разные события. Реклама, вакансии, розыгрыши, пустые ссылки и дубли должны получить низкий балл. Данные: '
                .json_encode(['candidate_target' => $contentPlan->candidate_target, 'posts' => $posts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]];

        foreach ($sourcePosts->filter(fn ($post): bool => blank($post->text))->flatMap->mediaAssets->where('type', MediaType::Photo)->take(8) as $asset) {
            if ($image = $this->imageDataUrl($asset->disk, $asset->path, $asset->mime_type)) {
                $content[] = ['type' => 'text', 'text' => 'Изображение из source_post_id '.$asset->mediable_id];
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
            }
        }

        $prompt = [
            'role' => 'user',
            'content' => $content,
        ];

        $draft = $this->rankingResult($this->execute(
            $contentPlan,
            AiOperation::RankAndCluster,
            $publication->analysis_model ?: config('services.openrouter.analysis_model'),
            [$this->systemMessage('Ты редактор русскоязычного новостного канала. Не выдумывай факты.'), $prompt],
            $this->rankingSchema(),
        ));

        if ($draft['clusters'] === []) {
            return $draft;
        }

        return $this->validateRanking(
            $contentPlan,
            $sourcePosts,
            $draft,
            $publication->analysis_model ?: config('services.openrouter.analysis_model'),
        );
    }

    public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
    {
        $plannedPost->loadMissing('contentPlan.publication.destination', 'storyCandidate.sourcePosts.sourceChannel', 'storyCandidate.sourcePosts.mediaAssets');
        $candidate = $plannedPost->storyCandidate;
        $publication = $plannedPost->contentPlan->publication;
        $signature = $publication->signatureMarkdown($publication->destination);
        $sources = $candidate->sourcePosts->map(fn ($post): array => [
            'source_post_id' => $post->id,
            'channel' => $post->sourceChannel->title,
            'text' => $post->text,
            'posted_at' => $post->posted_at->toIso8601String(),
        ])->all();
        $content = [[
            'type' => 'text',
            'text' => 'Перепиши инфоповод в готовый самостоятельный Telegram-пост. Не указывай источники и не добавляй неподтвержденные факты. '
                .'Используй только согласованные или однозначно подтвержденные сведения. Противоречивые детали не включай в текст и добавь риск source_conflict. '
                .'Разрешена обычная разметка Markdown: **жирный**, *курсив*, ~~зачеркнутый~~ и [текст](https://example.com). Другие конструкции Markdown не используй. '
                .'Пиши живо и естественно: 2–4 коротких абзаца, разнообразная структура, один уместный жирный акцент, от 0 до 2 уместных эмодзи. '
                .'Допустима одна короткая эмоциональная фраза, если она подходит новости; для серьезных тем исключи юмор и легкомысленные эмодзи. '
                .'Не повторяй один и тот же шаблон заголовка, вводную фразу или финал между постами. '
                .(filled($instruction) ? 'Дополнительная инструкция редактора: '.$instruction.'. ' : '')
                ."Язык: {$publication->language}. Тон: {$publication->tone_prompt}. Примеры желаемого тона (это ориентиры, не шаблоны): "
                .json_encode($publication->tone_examples ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .'. '.$this->signatureInstruction($signature).' Это правило подписи имеет приоритет над тоном, примерами и дополнительной инструкцией редактора. Запрещенные фразы: '
                .json_encode($publication->forbidden_phrases ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .'. Заголовок и материалы: '.json_encode([
                    'title' => $candidate->title,
                    'summary' => $candidate->summary,
                    'sources' => $sources,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]];

        foreach ($candidate->sourcePosts->flatMap->mediaAssets->where('type', MediaType::Photo)->take(4) as $asset) {
            if ($image = $this->imageDataUrl($asset->disk, $asset->path, $asset->mime_type)) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $image]];
            }
        }

        $result = $this->rewriteResult($this->execute(
            $plannedPost,
            AiOperation::Rewrite,
            $publication->rewrite_model ?: config('services.openrouter.rewrite_model'),
            [$this->systemMessage('Ты сильный редактор Telegram-канала. Сохраняй факты и смысл, избегай кликбейта, канцелярита и однообразных шаблонов.'), ['role' => 'user', 'content' => $content]],
            $this->rewriteSchema(),
            'v2',
        ));

        if ($this->hasExpectedSignature($result['text'], $signature)) {
            return $result;
        }

        $corrected = $this->rewriteResult($this->execute(
            $plannedPost,
            AiOperation::Rewrite,
            $publication->rewrite_model ?: config('services.openrouter.rewrite_model'),
            [
                $this->systemMessage('Исправь только подпись готового поста. Не меняй факты, стиль и остальной текст.'),
                ['role' => 'user', 'content' => 'Текст: '.json_encode($result['text'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'. '.$this->signatureInstruction($signature)],
            ],
            $this->rewriteSchema(),
            'v2',
        ));

        if (! $this->hasExpectedSignature($corrected['text'], $signature)) {
            Log::warning('OpenRouter returned an invalid publication signature after correction.', [
                'planned_post_id' => $plannedPost->id,
                'expected_signature' => $signature,
                'ai_run_id' => $corrected['ai_run_id'] ?? null,
            ]);
        }

        return $corrected;
    }

    public function reviewPlan(ContentPlan $contentPlan): array
    {
        $contentPlan->loadMissing('publication', 'plannedPosts.storyCandidate.sourcePosts.sourceChannel');
        $items = $contentPlan->plannedPosts->map(fn ($post): array => [
            'planned_post_id' => $post->id,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'text' => $post->text,
            'sources' => $post->storyCandidate->sourcePosts->map(fn (SourcePost $sourcePost): array => [
                'source_post_id' => $sourcePost->id,
                'channel' => $sourcePost->sourceChannel->title,
                'text' => Str::limit($sourcePost->text ?? '', 2000),
            ])->values()->all(),
        ])->all();

        return $this->reviewResult($this->execute(
            $contentPlan,
            AiOperation::ReviewPlan,
            $contentPlan->publication->analysis_model ?: config('services.openrouter.analysis_model'),
            [
                $this->systemMessage('Ты выпускающий редактор. Блокируй дубли, противоречия, недостоверные формулировки и нежелательный контент.'),
                ['role' => 'user', 'content' => 'Проверь план целиком и верни риски для каждого поста и группы смысловых дублей. '
                    .'Сверяй каждое фактическое утверждение с приложенными sources. Для сведений, которых нет в источниках, добавляй риск unsupported_claim и кратко указывай неподтвержденное утверждение в reason. '
                    .'Разметка Markdown, эмоциональная подача и подпись сами по себе не являются рисками: '
                    .json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            $this->reviewSchema(),
            'v2',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function execute(
        Model $subject,
        AiOperation $operation,
        string $model,
        array $messages,
        array $schema,
        string $promptVersion = 'v1',
    ): array {
        if (blank(config('services.openrouter.key'))) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $operation === AiOperation::Rewrite ? 0.6 : 0.1,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $operation->value, 'strict' => true, 'schema' => $schema],
            ],
            'provider' => ['require_parameters' => true],
        ];
        $run = AiRun::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'operation' => $operation,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'request_payload' => $this->redactImages($payload),
            'started_at' => now(),
        ]);

        try {
            $response = Http::baseUrl((string) config('services.openrouter.url'))
                ->withToken((string) config('services.openrouter.key'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout((int) config('services.openrouter.connect_timeout', 10))
                ->timeout((int) config('services.openrouter.timeout', 300))
                ->post('/chat/completions', $payload)
                ->throw();
            $body = $response->json();
            $content = data_get($body, 'choices.0.message.content');

            if (! is_string($content)) {
                throw new RuntimeException('OpenRouter returned an invalid structured response.');
            }

            $result = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            $run->update([
                'response_payload' => $body,
                'usage' => $body['usage'] ?? null,
                'cost_usd' => data_get($body, 'usage.cost'),
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $result['ai_run_id'] = $run->id;

            return $result;
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'error' => $exception->getMessage(), 'completed_at' => now()]);

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, SourcePost>  $sourcePosts
     * @param  RankingResult  $draft
     * @return RankingResult
     */
    private function validateRanking(
        ContentPlan $contentPlan,
        Collection $sourcePosts,
        array $draft,
        string $model,
    ): array {
        $referencedIds = collect($draft['clusters'])
            ->flatMap(fn (array $cluster): array => $cluster['source_post_ids'])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();
        $posts = $sourcePosts
            ->whereIn('id', $referencedIds)
            ->map(fn ($post): array => [
                'id' => $post->id,
                'channel' => $post->sourceChannel->title,
                'text' => Str::limit($post->text ?? '', 1800),
                'posted_at' => $post->posted_at->toIso8601String(),
            ])
            ->values()
            ->all();
        $prompt = [
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => 'Проверь черновые кластеры перед сохранением. Это строгий факт-чек связей, а не повторное творческое ранжирование. '
                    .'В одном кластере должны быть только сообщения об одном и том же конкретном событии. Общая тема, один бренд, молния, кино, еда или один источник не означают один инфоповод. '
                    .'Удаляй каждый source_post_id, текст которого не подтверждает заголовок и summary. Один source_post_id разрешено использовать максимум в одном итоговом кластере. '
                    .'Не объединяй разные фильмы, товары, заявления или события в тематические дайджесты. Если черновик смешал несколько событий, раздели его или оставь только связную часть. '
                    .'Перепиши title и summary так, чтобы они описывали исключительно оставшиеся источники. Не добавляй идентификаторы, которых нет в posts. '
                    .'Верни не более candidate_target кластеров. Данные: '
                    .json_encode([
                        'candidate_target' => $contentPlan->candidate_target,
                        'draft_clusters' => $draft['clusters'],
                        'posts' => $posts,
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]],
        ];

        return $this->rankingResult($this->execute(
            $contentPlan,
            AiOperation::ValidateClusters,
            $model,
            [
                $this->systemMessage('Ты выпускающий редактор и факт-чекер. Лучше удалить сомнительную связь, чем объединить разные новости.'),
                $prompt,
            ],
            $this->rankingSchema(),
        ));
    }

    /** @param array<string, int|float> $metrics */
    private function preliminaryScore(array $metrics, CarbonInterface $postedAt, float $channelWeight): float
    {
        $views = max(0, (int) ($metrics['views'] ?? 0));
        $forwards = max(0, (int) ($metrics['forwards'] ?? 0));
        $reactions = max(0, (int) ($metrics['reactions'] ?? 0));
        $freshness = max(0, 24 - $postedAt->diffInHours(now())) / 24;
        $score = log10($views + 1) * 10
            + log10($forwards + 1) * 8
            + log10($reactions + 1) * 6
            + $freshness * 20
            + min(10, $channelWeight * 5);

        return round(min(100, $score), 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactImages(array $payload): array
    {
        array_walk_recursive($payload, function (&$value, string|int $key): void {
            if ($key === 'url' && is_string($value) && str_starts_with($value, 'data:')) {
                $value = '[base64 image omitted]';
            }
        });

        return $payload;
    }

    /** @return array{role: string, content: string} */
    private function systemMessage(string $content): array
    {
        return ['role' => 'system', 'content' => $content];
    }

    private function signatureInstruction(?string $signature): string
    {
        if ($signature === null) {
            return 'Не добавляй подпись канала: последняя строка не должна состоять из @username или ссылки на канал.';
        }

        return 'Последней отдельной строкой обязательно добавь подпись в точности без изменений: '.$signature;
    }

    private function hasExpectedSignature(string $text, ?string $signature): bool
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];
        $lastLine = trim((string) end($lines));

        if ($signature !== null) {
            return $lastLine === $signature;
        }

        return preg_match('/^(?:@[A-Za-z0-9_]{5,}|\\?\[[^\]]+\]\(https?:\/\/(?:t\.me|telegram\.me)\/[^)]+\)|https?:\/\/(?:t\.me|telegram\.me)\/\S+|<a\b[^>]+(?:t\.me|telegram\.me)\/[^>]+>.*<\/a>)$/iu', $lastLine) !== 1;
    }

    private function imageDataUrl(?string $disk, ?string $path, ?string $mimeType): ?string
    {
        if (blank($disk) || blank($path) || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return 'data:'.($mimeType ?: 'image/jpeg').';base64,'.base64_encode(Storage::disk($disk)->get($path));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return RankingResult
     */
    private function rankingResult(array $result): array
    {
        if (! is_array($result['clusters'] ?? null)) {
            throw new RuntimeException('OpenRouter ranking response does not contain clusters.');
        }

        $clusters = [];

        foreach ($result['clusters'] as $cluster) {
            if (! is_array($cluster) || ! is_array($cluster['source_post_ids'] ?? null)) {
                throw new RuntimeException('OpenRouter returned an invalid story cluster.');
            }

            $sourceConflicts = $this->sourceConflicts($cluster['source_conflicts'] ?? []);
            $riskFlags = $this->stringList($cluster['risk_flags'] ?? []);

            if ($sourceConflicts !== [] && ! in_array('source_conflict', $riskFlags, true)) {
                $riskFlags[] = 'source_conflict';
            }

            $scoreBreakdown = is_array($cluster['score_breakdown'] ?? null) ? $cluster['score_breakdown'] : [];
            $scoreBreakdown['source_conflicts'] = $sourceConflicts;

            $clusters[] = [
                'source_post_ids' => array_values(array_map('intval', $cluster['source_post_ids'])),
                'title' => (string) ($cluster['title'] ?? ''),
                'summary' => (string) ($cluster['summary'] ?? ''),
                'score' => (float) ($cluster['score'] ?? 0),
                'score_breakdown' => $scoreBreakdown,
                'selection_reason' => (string) ($cluster['selection_reason'] ?? ''),
                'risk_flags' => $riskFlags,
                'source_conflicts' => $sourceConflicts,
            ];
        }

        return ['clusters' => $clusters];
    }

    /** @param array<string, mixed> $result
     * @return array{text: string, risk_flags?: list<string>, ai_run_id?: int}
     */
    private function rewriteResult(array $result): array
    {
        if (! is_string($result['text'] ?? null) || blank($result['text'])) {
            throw new RuntimeException('OpenRouter rewrite response does not contain text.');
        }

        return [
            'text' => $result['text'],
            'risk_flags' => $this->stringList($result['risk_flags'] ?? []),
            'ai_run_id' => isset($result['ai_run_id']) ? (int) $result['ai_run_id'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{items: list<array{planned_post_id: int, risk_flags?: list<string>, reason?: string}>, duplicate_groups?: list<list<int>>}
     */
    private function reviewResult(array $result): array
    {
        if (! is_array($result['items'] ?? null)) {
            throw new RuntimeException('OpenRouter plan review response does not contain items.');
        }

        $items = [];

        foreach ($result['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'planned_post_id' => (int) ($item['planned_post_id'] ?? 0),
                'risk_flags' => $this->stringList($item['risk_flags'] ?? []),
                'reason' => (string) ($item['reason'] ?? ''),
            ];
        }

        $duplicateGroups = [];

        foreach (is_array($result['duplicate_groups'] ?? null) ? $result['duplicate_groups'] : [] as $group) {
            if (is_array($group)) {
                $duplicateGroups[] = array_values(array_map('intval', $group));
            }
        }

        return ['items' => $items, 'duplicate_groups' => $duplicateGroups];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }

    /**
     * @return list<array{fact: string, variants: list<string>, source_post_ids: list<int>}>
     */
    private function sourceConflicts(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn (mixed $conflict): bool => is_array($conflict) && filled($conflict['fact'] ?? null))
            ->map(fn (array $conflict): array => [
                'fact' => (string) $conflict['fact'],
                'variants' => $this->stringList($conflict['variants'] ?? []),
                'source_post_ids' => array_values(array_map('intval', is_array($conflict['source_post_ids'] ?? null) ? $conflict['source_post_ids'] : [])),
            ])
            ->values()
            ->all());
    }

    /** @return array<string, mixed> */
    private function rankingSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['clusters'], 'properties' => [
            'clusters' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['source_post_ids', 'title', 'summary', 'score', 'score_breakdown', 'selection_reason', 'risk_flags', 'source_conflicts'], 'properties' => [
                'source_post_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                'title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'score' => ['type' => 'number'],
                'score_breakdown' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['freshness', 'reach', 'engagement', 'source_weight', 'value', 'originality'], 'properties' => [
                    'freshness' => ['type' => 'number'], 'reach' => ['type' => 'number'], 'engagement' => ['type' => 'number'],
                    'source_weight' => ['type' => 'number'], 'value' => ['type' => 'number'], 'originality' => ['type' => 'number'],
                ]], 'selection_reason' => ['type' => 'string'],
                'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'source_conflicts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['fact', 'variants', 'source_post_ids'], 'properties' => [
                    'fact' => ['type' => 'string'],
                    'variants' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'source_post_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ]]],
            ]]],
        ]];
    }

    /** @return array<string, mixed> */
    private function rewriteSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['text', 'risk_flags'], 'properties' => [
            'text' => ['type' => 'string'], 'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
    }

    /** @return array<string, mixed> */
    private function reviewSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['items', 'duplicate_groups'], 'properties' => [
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['planned_post_id', 'risk_flags', 'reason'], 'properties' => [
                'planned_post_id' => ['type' => 'integer'], 'risk_flags' => ['type' => 'array', 'items' => ['type' => 'string']], 'reason' => ['type' => 'string'],
            ]]],
            'duplicate_groups' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2]],
        ]];
    }
}
