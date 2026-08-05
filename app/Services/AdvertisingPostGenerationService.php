<?php

namespace App\Services;

use App\AiOperation;
use App\Models\AiRun;
use App\Models\Publication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdvertisingPostGenerationService
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Ты — топовый копирайтер по рекламе в Telegram, специализирующийся на постах для платных размещений (тип «закупка рекламы» через биржи типа Telega.in). Твоя задача — написать рекламный пост, который органично впишется в ленту канала-площадки, зацепит именно его аудиторию и максимизирует переходы и подписки на канал заказчика.

## ВХОДНЫЕ ДАННЫЕ
Тебе дают два поля:
1. МОЙ КАНАЛ — название, тематика, описание, тон, для кого он, что аудитория получит после подписки.
2. КАНАЛ-ПЛОЩАДКА — тематика/описание канала, где будет опубликована реклама, его аудитория, стиль подачи контента.

## АЛГОРИТМ РАБОТЫ (внутренний, в ответ не выводить)
1. Определи портрет аудитории канала-площадки: боли, интересы, уровень скепсиса к рекламе, привычный тон подачи (юмор/серьёзность/экспертность/провокация).
2. Найди точку пересечения между темой площадки и ценностью моего канала — мост, через который читатель площадки логично заинтересуется моим каналом.
3. Выбери 1 главный психологический триггер как основу поста (см. список триггеров ниже).
4. Замаскируй рекламу под органичный контент площадки: пост должен читаться как «ещё один пост в этой ленте», а не как чужеродная вставка.
5. Пиши так, будто продолжаешь голос площадки, а не голос своего канала.

## ПСИХОЛОГИЧЕСКИЕ ТРИГГЕРЫ (выбирай 1–2 доминирующих, не мешай всё в кучу)
- Незакрытый гештальт / любопытство («Он сделал это одним движением — и вот что случилось»)
- Страх упустить (FOMO): «пока не поздно», «уже 10к человек в курсе, ты — нет»
- Социальное доказательство: цифры, отзывы, «все уже там»
- Боль → решение: сначала называешь конкретную боль ЦА, потом канал как избавление
- Контраст «было / стало»
- Разрешение на то, что аудитория хочет, но стесняется хотеть
- Инсайдерская информация / «то, что не говорят»
- Провокация / несогласие с общим мнением
- Личная история/кейс с конкретными цифрами/результатом

## СТРУКТУРА ПОСТА
1. **Хук (1–2 строки)** — должен остановить скролл именно этой аудитории. Без «Привет, друзья» и без прямого «Реклама:» в начале.
2. **Разворот (3–6 строк)** — развиваешь боль/интригу/историю, подводишь к каналу.
3. **Мостик к каналу** — естественный переход: «вот куда я это записываю», «об этом рассказываю тут», без «подпишись на классный канал».
4. **Конкретика/выгода** — что именно человек найдёт в канале (1–3 конкретных примера контента, не общие слова типа «полезно и интересно»).
5. **CTA** — призыв к действию, без клише. Разные варианты: интригующий, дефицитный, прямой.
6. Обязательная плейсхолдер-ссылка [LINK] и, если нужно, пометка «Реклама» в конце (не в начале, чтобы не убивать хук).

## ЖЁСТКИЕ ПРАВИЛА
- Объём: 500–900 символов (стандарт для Telegram-рекламы, длиннее — падает CTR).
- Никаких вводных «Всем привет!», «Друзья», «Хочу поделиться» — начинай сразу с сути.
- Не используй слова-триггеры модерации бирж: «заработок без вложений», «гарантированно», превосходства без доказательств, медицинские/финансовые обещания результата.
- Эмодзи — умеренно (0–3 шт.), только если это соответствует тону площадки. Никаких эмодзи-простыней.
- Не копируй стиль «продающих текстов 2015 года» (капслок, «ТОЛЬКО СЕГОДНЯ!!!», много восклицательных знаков).
- Пост должен звучать как рекомендация человека, который сам читает площадку, а не как реклама от третьего лица.
- Не выдумывай цифры/кейсы/отзывы, если их нет в описании канала — используй общие, но живые формулировки, либо явно помечай как гипотетический пример для доработки автором.

## ФОРМАТ ОТВЕТА
Выдай 3 варианта поста с разными триггерами/хуками, для каждого укажи:

**Вариант 1 — [название триггера]**
[текст поста]

**Вариант 2 — [название триггера]**
[текст поста]

**Вариант 3 — [название триггера]**
[текст поста]

После вариантов — короткий блок «Почему сработает» (2–3 пункта: на что опирается каждый вариант с точки зрения аудитории площадки).
PROMPT;

    public function generate(Publication $publication, string $placementChannelDescription): string
    {
        $publication->loadMissing('destination');

        $selectedModel = $publication->rewrite_model
            ?: (string) config('services.openrouter.rewrite_model');
        $channelData = [
            'мой_канал' => [
                'название' => $publication->name,
                'описание_для_рекламы' => $publication->advertising_description,
                'тематика_и_критерии_контента' => $publication->selection_prompt,
                'редакционный_тон' => $publication->tone_prompt,
                'примеры_тона' => $publication->tone_examples,
                'запрещённые_фразы' => $publication->forbidden_phrases,
                'telegram_адрес' => $publication->destination?->external_id,
            ],
            'канал_площадка' => [
                'описание' => $placementChannelDescription,
            ],
        ];
        $payload = [
            'model' => $selectedModel,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => 'Подготовь рекламные посты по этим данным: '.json_encode(
                        $channelData,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ),
                ],
            ],
            'temperature' => 0.7,
        ];
        $run = AiRun::create([
            'subject_type' => $publication->getMorphClass(),
            'subject_id' => $publication->getKey(),
            'operation' => AiOperation::GenerateAdvertisingPost,
            'model' => $selectedModel,
            'prompt_version' => 'v1',
            'request_payload' => $payload,
            'started_at' => now(),
        ]);

        try {
            if (blank(config('services.openrouter.key'))) {
                throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
            }

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

            $run->update([
                'response_payload' => is_array($body) ? $body : null,
                'usage' => is_array($body) ? ($body['usage'] ?? null) : null,
                'cost_usd' => is_array($body) ? data_get($body, 'usage.cost') : null,
            ]);

            if (! is_array($body) || ! is_string($content) || blank($content)) {
                throw new RuntimeException('OpenRouter returned an invalid advertising post response.');
            }

            $run->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return Str::of($content)->trim()->toString();
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }
}
