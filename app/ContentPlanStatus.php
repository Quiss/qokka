<?php

namespace App;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContentPlanStatus: string implements HasColor, HasLabel
{
    case Generating = 'generating';
    case CandidateReview = 'candidate_review';
    case Rewriting = 'rewriting';
    case NeedsCandidates = 'needs_candidates';
    case FinalReview = 'final_review';
    case Ready = 'ready';
    case Active = 'active';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /** @return list<self> */
    public static function editorialPriority(): array
    {
        return [
            self::CandidateReview,
            self::FinalReview,
            self::NeedsCandidates,
            self::Failed,
            self::Ready,
            self::Rewriting,
            self::Generating,
            self::Active,
            self::Skipped,
            self::Completed,
        ];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Generating => 'ИИ отбирает новости',
            self::CandidateReview => 'Утверждение плана',
            self::Rewriting => 'Рерайт',
            self::NeedsCandidates => 'Нужен добор',
            self::FinalReview => 'Проверка рерайта',
            self::Ready => 'Готов к публикации',
            self::Active => 'Публикуется',
            self::Completed => 'Завершён',
            self::Skipped => 'Пропущен: нет безопасных новостей',
            self::Failed => 'Ошибка',
        };
    }

    /** @return array<int, string> */
    public function getColor(): array
    {
        return match ($this) {
            self::Generating => Color::Sky,
            self::CandidateReview => Color::Amber,
            self::Rewriting => Color::Violet,
            self::NeedsCandidates => Color::Orange,
            self::FinalReview => Color::Fuchsia,
            self::Ready => Color::Emerald,
            self::Active => Color::Cyan,
            self::Completed => Color::Slate,
            self::Skipped => Color::Stone,
            self::Failed => Color::Rose,
        };
    }
}
