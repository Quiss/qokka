<?php

namespace App\Services;

use App\AiOperation;
use App\Models\AiRun;
use App\Models\Publication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TonGenerationService
{
    private const string PROMPT_TEMPLATE = <<<'PROMPT'
Ты придумываешь "тон" для Telegram-канала, который переписывает (рерайтит)
новости и материалы под конкретный стиль. Результат должен звучать как живой
человек с характером, а не как нейтральный рерайт-бот, и не повторять одну
структуру от поста к посту.

На входе тебе дадут:
- тему канала и его аудиторию;
- при наличии — примеры постов, которые уже кажутся шаблонными/ботными;
- при наличии — пример поста в желаемом тоне (только для ориентира по тону, не
  для копирования формулировок).

Сделай следующее:

1. Придумай голос: конкретный характер, темп речи, уровень иронии или теплоты.
   Голос принадлежит человеку с точкой зрения, а не безликому редактору.
   Подбери голос под тему и аудиторию — не бери нейтральный вариант по
   умолчанию.

   Если добавляешь любимые слова-связки — их должно быть не больше 2–3, и
   обязательно зафиксируй для них ограничение: это приправа для середины
   текста, а не стартовые слова поста. Явно запиши, что связки нельзя
   использовать в первом предложении поста и нельзя использовать в двух
   соседних постах подряд — иначе они станут новым шаблоном вместо старого.

2. Составь список из 10–15 слов и фраз-клише именно для этой темы, которые
   запрещены. Если дали примеры "ботных" постов — вытащи буквальные повторы
   из них. Добавь типовые штампы жанра (что-то в духе "стало известно",
   "не оставит равнодушным", "погружение в мир", "яркие эмоции", "настоящая
   находка", "стоит отметить" — но подбирай конкретно под тему, а не общий
   список).

3. Дай 3–4 разных способа начать пост для этой темы (факт, деталь, вопрос
   читателю, практическая цифра/цена/дата, честное наблюдение) — чтобы посты
   не начинались одинаково. Ни один из вариантов начала не должен совпадать
   со словами-связками из пункта 1.

4. Дай правило подачи практической информации (адрес/цена/дата/время, если
   они важны для темы): она не обязана всегда идти последней строкой с одним
   и тем же эмодзи — иногда логичнее в начале, иногда внутри фразы.

5. Дай 3–4 разных типа финала: без обязательного призыва к действию и без
   одинаковой эмодзи-концовки каждый раз.

6. Дай короткое правило по эмодзи для этой темы: сколько уместно и как их
   расставлять, чтобы они не приклеивались по инерции к каждому абзацу.

Не пиши пояснений о своей работе и не оценивай задачу — выведи только готовый
блок правил на русском, в структуре ниже, чтобы его можно было вставить в
system-промпт канала одним куском.

ФОРМАТ ОТВЕТА

ГОЛОС
[2–3 предложения, включая ограничение на использование связок, если они есть]

ЗАХОД БЕЗ ШАБЛОНА
[3–4 варианта начала]

ЗАПРЕЩЕНО
[10–15 клише]

ФАКТУРА БЕЗ ШАБЛОНА
[правило про адрес/цену/дату, если применимо к теме]

ФИНАЛ БЕЗ ШАБЛОНА
[3–4 типа финала]

ЭМОДЗИ
[короткое правило]

────────────────────────────────────────
ВХОДНЫЕ ДАННЫЕ

Тема канала: [тема]
Аудиория: [аудитория]
Примеры постов-клише (если есть): [вставить или "нет"]
Пример желаемого тона (если есть): [вставить или "нет"]
PROMPT;

    public function generate(
        string $topic,
        string $audience,
        ?string $clicheExamples = null,
        ?string $desiredToneExample = null,
        ?Publication $publication = null,
        ?string $model = null,
    ): string {
        if (blank(config('services.openrouter.key'))) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $selectedModel = $model
            ?: $publication?->rewrite_model
            ?: (string) config('services.openrouter.rewrite_model');
        $prompt = Str::replace(
            [
                'Тема канала: [тема]',
                'Аудиория: [аудитория]',
                'Примеры постов-клише (если есть): [вставить или "нет"]',
                'Пример желаемого тона (если есть): [вставить или "нет"]',
            ],
            [
                'Тема канала: '.$topic,
                'Аудиория: '.$audience,
                'Примеры постов-клише (если есть): '.(filled($clicheExamples) ? $clicheExamples : 'нет'),
                'Пример желаемого тона (если есть): '.(filled($desiredToneExample) ? $desiredToneExample : 'нет'),
            ],
            self::PROMPT_TEMPLATE,
        );
        $payload = [
            'model' => $selectedModel,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ];
        $run = AiRun::create([
            'subject_type' => $publication?->getMorphClass(),
            'subject_id' => $publication?->getKey(),
            'operation' => AiOperation::GenerateTone,
            'model' => $selectedModel,
            'prompt_version' => 'v1',
            'request_payload' => $payload,
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

            if (! is_array($body) || ! is_string($content) || blank($content)) {
                throw new RuntimeException('OpenRouter returned an invalid tone response.');
            }

            $run->update([
                'response_payload' => $body,
                'usage' => $body['usage'] ?? null,
                'cost_usd' => data_get($body, 'usage.cost'),
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
