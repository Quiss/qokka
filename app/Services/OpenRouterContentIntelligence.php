<?php

namespace App\Services;

use App\AiOperation;
use App\Contracts\FallbackContentIntelligence;
use App\MediaType;
use App\Models\AiRun;
use App\Models\ContentPlan;
use App\Models\MediaAsset;
use App\Models\PlannedPost;
use App\Models\Publication;
use App\Models\SourcePost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
class OpenRouterContentIntelligence implements FallbackContentIntelligence
{
    public function __construct(
        private readonly RecentPublicationHistory $recentPublicationHistory,
    ) {}

    public function rankAndCluster(ContentPlan $contentPlan, Collection $sourcePosts): array
    {
        return $this->rankAndClusterUsingConfiguredModel($contentPlan, $sourcePosts);
    }

    public function rankAndClusterWithFallback(ContentPlan $contentPlan, Collection $sourcePosts): array
    {
        return $this->rankAndClusterUsingConfiguredModel($contentPlan, $sourcePosts, true);
    }

    private function rankAndClusterUsingConfiguredModel(
        ContentPlan $contentPlan,
        Collection $sourcePosts,
        bool $useFallbackModel = false,
    ): array {
        $publication = $contentPlan->publication;
        $model = $this->analysisModel($publication, $useFallbackModel);
        $recentCommittedPosts = $this->recentPublicationHistory->forPlan($contentPlan);
        $rankingData = [
            ...$this->temporalPlanContext($contentPlan),
            'candidate_target' => $contentPlan->candidate_target,
            'recent_committed_posts' => $recentCommittedPosts,
            'posts' => $sourcePosts->map(fn ($post): array => [
                'id' => $post->id,
                'source' => $post->source->title,
                'source_type' => $post->source->type->value,
                'source_weight' => (float) $post->source->weight,
                'content_kind' => $post->isCollection() ? 'collection' : 'article',
                'text' => Str::limit($post->text ?? '', 1500),
                'metrics' => $post->metrics,
                'metrics_available' => ! $post->isCollection(),
                'posted_at' => $post->posted_at->toIso8601String(),
                'preliminary_score' => $post->isCollection()
                    ? null
                    : $this->preliminaryScore($post->metrics ?? [], $post->posted_at, (float) $post->source->weight),
                'structured_content' => $post->collectionPayload(),
                'media' => $post->mediaAssets
                    ->map(fn (MediaAsset $asset): string => $asset->type->value)
                    ->all(),
            ])->values()->all(),
        ];

        if (filled($publication->selection_prompt)) {
            $rankingData['selection_prompt'] = $publication->selection_prompt;
        }

        $content = [[
            'type' => 'text',
            'text' => 'Сгруппируй сообщения об одном инфоповоде, оцени новостную ценность от 0 до 100 и верни лучшие кластеры. '
                .$this->selectionFilterInstruction($publication->selection_prompt)
                .'Учитывай предварительный балл, свежесть, охват, реакции, вес источника, практическую ценность и оригинальность. Отсутствующие метрики нейтральны и не равны нулевой популярности. '
                .'Материал с content_kind=collection — готовая редакционная подборка: оценивай её целиком, не разделяй items на разные кластеры и не смешивай с посторонними событиями. '
                .'Отбирай инфоповод только если он останется актуальным к указанным слотам публикации. '
                .'Не включай прогноз погоды, дорожное ограничение, отключение, расписание, анонс или другую оперативную информацию, если она относится к более ранней дате или закончится до публикации. '
                .'Относительные слова «сегодня», «завтра» и подобные трактуй относительно posted_at источника. Не переноси старый факт на дату плана и не исправляй дату догадкой. '
                .'Если источники противоречат друг другу, перечисли конфликтующие факты в source_conflicts и добавь флаг source_conflict. '
                .'Не возвращай инфоповод, если то же конкретное событие уже есть в recent_committed_posts, даже когда новый источник пересказывает его другими словами. '
                .'Продолжение с новым существенным фактом или новым этапом события не является дублем только из-за общей темы. '
                .'Не объединяй похожие, но разные события. Реклама, вакансии, розыгрыши, пустые ссылки и дубли должны получить низкий балл. Данные: '
                .json_encode($rankingData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
            $model,
            [$this->systemMessage('Ты редактор русскоязычного новостного канала. Не выдумывай факты.'), $prompt],
            $this->rankingSchema(),
            'v6',
        ));

        if ($draft['clusters'] === []) {
            return $draft;
        }

        return $this->validateRanking(
            $contentPlan,
            $sourcePosts,
            $draft,
            $model,
        );
    }

    public function rewrite(PlannedPost $plannedPost, ?string $instruction = null): array
    {
        return $this->rewriteUsingConfiguredModel($plannedPost, $instruction);
    }

    public function rewriteWithFallback(PlannedPost $plannedPost, ?string $instruction = null): array
    {
        return $this->rewriteUsingConfiguredModel($plannedPost, $instruction, true);
    }

    private function rewriteUsingConfiguredModel(
        PlannedPost $plannedPost,
        ?string $instruction = null,
        bool $useFallbackModel = false,
    ): array {
        $plannedPost->loadMissing('contentPlan.publication.destination', 'storyCandidate.sourcePosts.source', 'storyCandidate.sourcePosts.mediaAssets');
        $candidate = $plannedPost->storyCandidate;
        $publication = $plannedPost->contentPlan->publication;
        $model = $this->rewriteModel($publication, $useFallbackModel);
        $signature = $publication->signatureMarkdown($publication->destination);
        $publicationMoment = $plannedPost->scheduled_at?->setTimezone($publication->timezone);
        $recentPosts = PlannedPost::query()
            ->where('id', '<>', $plannedPost->id)
            ->whereNotNull('text')
            ->whereHas(
                'contentPlan',
                fn (Builder $query): Builder => $query->where('publication_id', $publication->id),
            )
            ->latest('id')
            ->limit(5)
            ->pluck('text')
            ->filter(fn (?string $text): bool => filled($text))
            ->map(fn (string $text): string => Str::limit($text, 600))
            ->values()
            ->all();
        $sources = $candidate->sourcePosts->map(fn ($post): array => [
            'source_post_id' => $post->id,
            'source' => $post->source->title,
            'source_type' => $post->source->type->value,
            'content_kind' => $post->isCollection() ? 'collection' : 'article',
            'text' => $post->text,
            'structured_content' => $post->collectionPayload(),
            'posted_at' => $post->posted_at->toIso8601String(),
        ])->all();
        $content = [[
            'type' => 'text',
            'text' => 'Перепиши инфоповод в готовый самостоятельный Telegram-пост. Не указывай источники и не добавляй неподтвержденные факты. '
                .'Используй только согласованные или однозначно подтвержденные сведения. Противоречивые детали не включай в текст и добавь риск source_conflict. '
                .'Если источник содержит content_kind=collection, создай один пост по всей подборке и упомяни каждый item; не разделяй подборку и не выдумывай отсутствующие факты или поля. '
                .'Плановая публикация: '.($publicationMoment?->toIso8601String() ?? 'не задана').", часовой пояс: {$publication->timezone}. "
                .'Проверь, будет ли инфоповод актуален в этот момент. Если прогноз, ограничение, отключение, расписание, анонс или другая оперативная информация относится к уже прошедшей дате, не подменяй дату и добавь риск stale_at_publication. '
                .'Слова «сегодня», «завтра» и подобные в источниках трактуй относительно posted_at источника, а в готовом тексте используй только тогда, когда они однозначно верны для момента публикации. '
                .'Разрешена обычная разметка Markdown: **жирный**, *курсив*, ~~зачеркнутый~~, [текст](https://example.com) и отдельная цитата в формате > текст. Другие конструкции Markdown не используй. '
                .'Цитируй только слова, которые дословно присутствуют в материалах; не придумывай цитаты и не превращай пересказ в прямую речь. '
                .(filled($instruction) ? 'Разовая дополнительная инструкция редактора для этого рерайта: '.$instruction.'. При конфликте стилевых требований она имеет приоритет над постоянной инструкцией канала. ' : '')
                ."Язык: {$publication->language}. Редакционная инструкция канала — единственный источник постоянных требований к тону, объему, структуре, началу и финалу, заголовкам, акцентам, цитатам, эмодзи и юмору: {$publication->tone_prompt}. "
                .'Если редакционная инструкция не регулирует отдельный стилевой параметр, выбери его по смыслу конкретной новости. Примеры желаемого текста (это ориентиры, не шаблоны и не источники фактов): '
                .json_encode($publication->tone_examples ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                .'. Недавние тексты этой же публикации используй только для выполнения требований редакционной инструкции о разнообразии и повторяемости; это не шаблоны и не источники фактов: '
                .json_encode($recentPosts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
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
            $model,
            [$this->systemMessage('Ты редактор Telegram-канала. Строго следуй редакционной инструкции конкретного канала, сохраняя подтвержденные факты и смысл.'), ['role' => 'user', 'content' => $content]],
            $this->rewriteSchema(),
            'v6',
        ));

        if ($this->hasExpectedSignature($result['text'], $signature)) {
            return $result;
        }

        $corrected = $this->rewriteResult($this->execute(
            $plannedPost,
            AiOperation::Rewrite,
            $model,
            [
                $this->systemMessage('Исправь только подпись готового поста. Не меняй факты, стиль и остальной текст.'),
                ['role' => 'user', 'content' => 'Текст: '.json_encode($result['text'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).'. '.$this->signatureInstruction($signature)],
            ],
            $this->rewriteSchema(),
            'v6',
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
        return $this->reviewPlanUsingConfiguredModel($contentPlan);
    }

    public function reviewPlanWithFallback(ContentPlan $contentPlan): array
    {
        return $this->reviewPlanUsingConfiguredModel($contentPlan, true);
    }

    private function reviewPlanUsingConfiguredModel(
        ContentPlan $contentPlan,
        bool $useFallbackModel = false,
    ): array {
        $contentPlan->loadMissing('publication', 'plannedPosts.storyCandidate.sourcePosts.source');
        $items = $contentPlan->plannedPosts->map(fn ($post): array => [
            'planned_post_id' => $post->id,
            'scheduled_at' => $post->scheduled_at?->setTimezone($contentPlan->publication->timezone)->toIso8601String(),
            'text' => $post->text,
            'sources' => $post->storyCandidate->sourcePosts->map(fn (SourcePost $sourcePost): array => [
                'source_post_id' => $sourcePost->id,
                'source' => $sourcePost->source->title,
                'source_type' => $sourcePost->source->type->value,
                'content_kind' => $sourcePost->isCollection() ? 'collection' : 'article',
                'text' => Str::limit($sourcePost->text ?? '', 2000),
                'structured_content' => $sourcePost->collectionPayload(),
                'posted_at' => $sourcePost->posted_at->toIso8601String(),
            ])->values()->all(),
        ])->all();
        $reviewData = [
            ...$this->temporalPlanContext($contentPlan),
            'recent_committed_posts' => $this->recentPublicationHistory->forPlan($contentPlan),
            'items' => $items,
        ];

        return $this->reviewResult($this->execute(
            $contentPlan,
            AiOperation::ReviewPlan,
            $this->analysisModel($contentPlan->publication, $useFallbackModel),
            [
                $this->systemMessage('Ты выпускающий редактор. Блокируй дубли, противоречия, недостоверные формулировки и нежелательный контент.'),
                ['role' => 'user', 'content' => 'Проверь план целиком и верни риски для каждого поста и группы смысловых дублей. '
                    .'Сверяй каждое фактическое утверждение с приложенными sources. Для сведений, которых нет в источниках, добавляй риск unsupported_claim и кратко указывай неподтвержденное утверждение в reason. '
                    .'Сравни каждый пост с recent_committed_posts. Если это повтор того же конкретного события, добавь риск duplicate_recent_publication и укажи historical id в reason. '
                    .'Не считай дублем продолжение с новым существенным фактом или новым этапом события только из-за общей темы. '
                    .'Сопоставь posted_at каждого источника с scheduled_at поста и часовым поясом плана. Если погода, ограничение, отключение, расписание, анонс или другая оперативная информация устареет до публикации, добавь риск stale_at_publication. '
                    .'Не исправляй дату догадкой и не считай старый прогноз актуальным только из-за свежей формулировки рерайта. '
                    .'Разметка Markdown, эмоциональная подача и подпись сами по себе не являются рисками: '
                    .json_encode($reviewData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            $this->reviewSchema(),
            'v6',
        ));
    }

    private function analysisModel(Publication $publication, bool $useFallbackModel): string
    {
        if ($useFallbackModel && filled(config('services.openrouter.analysis_fallback_model'))) {
            return (string) config('services.openrouter.analysis_fallback_model');
        }

        return (string) ($publication->analysis_model ?: config('services.openrouter.analysis_model'));
    }

    private function rewriteModel(Publication $publication, bool $useFallbackModel): string
    {
        if ($useFallbackModel && filled(config('services.openrouter.rewrite_fallback_model'))) {
            return (string) config('services.openrouter.rewrite_fallback_model');
        }

        return (string) ($publication->rewrite_model ?: config('services.openrouter.rewrite_model'));
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
                'source' => $post->source->title,
                'source_type' => $post->source->type->value,
                'content_kind' => $post->isCollection() ? 'collection' : 'article',
                'text' => Str::limit($post->text ?? '', 1800),
                'structured_content' => $post->collectionPayload(),
                'posted_at' => $post->posted_at->toIso8601String(),
            ])
            ->values()
            ->all();
        $validationData = [
            ...$this->temporalPlanContext($contentPlan),
            'candidate_target' => $contentPlan->candidate_target,
            'draft_clusters' => $draft['clusters'],
            'posts' => $posts,
        ];

        if (filled($contentPlan->publication->selection_prompt)) {
            $validationData['selection_prompt'] = $contentPlan->publication->selection_prompt;
        }
        $prompt = [
            'role' => 'user',
            'content' => [[
                'type' => 'text',
                'text' => 'Проверь черновые кластеры перед сохранением. Это строгий факт-чек связей, а не повторное творческое ранжирование. '
                    .$this->selectionFilterInstruction($contentPlan->publication->selection_prompt)
                    .'В одном кластере должны быть только сообщения об одном и том же конкретном событии. Общая тема, один бренд, молния, кино, еда или один источник не означают один инфоповод. '
                    .'Исключение: source_post с content_kind=collection уже является единой редакционной подборкой. Сохрани такой source_post атомарным, не разделяй его items и не отклоняй только потому, что в нём несколько фильмов или сериалов. '
                    .'Удаляй каждый source_post_id, текст которого не подтверждает заголовок и summary. Один source_post_id разрешено использовать максимум в одном итоговом кластере. '
                    .'Для обычных article не объединяй разные фильмы, товары, заявления или события в тематические дайджесты. Если черновик смешал несколько событий, раздели его или оставь только связную часть. '
                    .'Удаляй кластеры с прогнозом погоды, ограничением, отключением, расписанием, анонсом или другой оперативной информацией, которая относится к дате раньше плана либо закончится до возможного слота публикации. '
                    .'Относительные даты считай от posted_at источника; не заменяй исходную дату датой плана. '
                    .'Перепиши title и summary так, чтобы они описывали исключительно оставшиеся источники. Не добавляй идентификаторы, которых нет в posts. '
                    .'Верни не более candidate_target кластеров. Данные: '
                    .json_encode($validationData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
            'v6',
        ));
    }

    /** @return array{plan_date: string, publication_timezone: string, publication_slots: list<string>, evaluated_at: string} */
    private function temporalPlanContext(ContentPlan $contentPlan): array
    {
        $timezone = $contentPlan->publication->timezone;

        return [
            'plan_date' => $contentPlan->plan_date->toDateString(),
            'publication_timezone' => $timezone,
            'publication_slots' => array_map(
                fn (string $slot): string => CarbonImmutable::parse($slot)
                    ->setTimezone($timezone)
                    ->toIso8601String(),
                $contentPlan->slot_schedule ?? [],
            ),
            'evaluated_at' => now()->setTimezone($timezone)->toIso8601String(),
        ];
    }

    private function selectionFilterInstruction(?string $selectionPrompt): string
    {
        if (blank($selectionPrompt)) {
            return '';
        }

        return 'Примени selection_prompt из данных как обязательный тематический фильтр. '
            .'Полностью исключи не соответствующие ему инфоповоды независимо от их охвата, свежести и предварительного балла. '
            .'Не заполняй candidate_target нерелевантными новостями: верни меньше кластеров или пустой список, если подходящих материалов недостаточно. '
            .'В selection_reason каждого оставленного кластера объясни, как он соответствует selection_prompt. ';
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
