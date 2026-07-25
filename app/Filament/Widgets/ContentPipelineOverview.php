<?php

namespace App\Filament\Widgets;

use App\CandidateStatus;
use App\DeliveryStatus;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Models\Delivery;
use App\Models\PlannedPost;
use App\Models\SourcePost;
use App\Models\StoryCandidate;
use App\PlannedPostStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ContentPipelineOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Собрано за 24 часа', SourcePost::query()->where('posted_at', '>=', now()->subDay())->count()),
            Stat::make('Кандидатов на модерации', StoryCandidate::query()->where('status', CandidateStatus::Pending)->count())
                ->color('warning'),
            Stat::make('Постов на финальной модерации', PlannedPost::query()->whereIn('status', [PlannedPostStatus::FinalReview, PlannedPostStatus::Blocked])->count())
                ->color('info'),
            Stat::make('Проблемных доставок', Delivery::query()->whereIn('status', [DeliveryStatus::NeedsReview, DeliveryStatus::Failed])->count())
                ->description('Открыть доставки, требующие решения')
                ->url(DeliveryResource::getUrl('index'))
                ->color('danger'),
        ];
    }
}
