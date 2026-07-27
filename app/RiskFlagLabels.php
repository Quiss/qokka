<?php

namespace App;

final class RiskFlagLabels
{
    public static function label(string $riskFlag): string
    {
        return match ($riskFlag) {
            'unsupported_claim' => 'Есть неподтверждённое утверждение',
            'source_conflict' => 'Источники расходятся в деталях',
            'unreliable_content' => 'Сведения требуют ручной проверки',
            'possible_duplicate' => 'Возможен дубликат',
            'duplicate_in_daily_plan' => 'Дубликат в текущем плане',
            'duplicate_recent_publication' => 'Повтор недавней публикации',
            'older_than_24h' => 'Новость старше 24 часов',
            'stale_at_publication' => 'Информация устареет к публикации',
            'ai_review_missing' => 'Не получена итоговая проверка ИИ',
            default => 'Требует ручной проверки',
        };
    }

    /**
     * @param  array<array-key, mixed>  $riskFlags
     * @return list<string>
     */
    public static function labels(array $riskFlags): array
    {
        return array_values(array_map(
            fn (mixed $riskFlag): string => self::label((string) $riskFlag),
            $riskFlags,
        ));
    }
}
