<?php

namespace App\Listeners;

use App\Actions\QueueOperationsNotification;
use App\Jobs\DispatchDueDeliveriesJob;
use App\Jobs\DownloadMediaAssetJob;
use App\Jobs\GenerateCandidateBatchJob;
use App\Jobs\IngestTelegramUpdateJob;
use App\Jobs\PublishDeliveryJob;
use App\Jobs\ReplenishContentPlanCandidatesJob;
use App\Jobs\ReviewContentPlanJob;
use App\Jobs\RewritePlannedPostJob;
use App\Jobs\SendOperationsNotificationJob;
use App\Jobs\SyncSourceChannelStatisticsJob;
use App\Jobs\VerifySourceChannelAccessJob;
use App\OperationsNotificationTopic;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;

class QueueFailedJobOperationsNotification
{
    public function __construct(
        private readonly QueueOperationsNotification $queueOperationsNotification,
    ) {}

    public function handle(JobFailed $event): void
    {
        $jobName = $event->job->resolveName();

        if ($jobName === SendOperationsNotificationJob::class) {
            return;
        }

        $error = Str::squish($event->exception->getMessage());

        $this->queueOperationsNotification->handle(
            OperationsNotificationTopic::Failures,
            'Терминальный сбой: '.$this->label($jobName),
            [
                'Очередь: '.$event->connectionName.'/'.$event->job->getQueue(),
                'Задача: '.$jobName,
                'Ошибка: '.($error !== '' ? $error : $event->exception::class),
            ],
            route('filament.admin.pages.dashboard'),
        );
    }

    private function label(string $jobName): string
    {
        return match ($jobName) {
            GenerateCandidateBatchJob::class => 'не удалось собрать контент-план',
            ReplenishContentPlanCandidatesJob::class => 'не удалось дополнить контент-план',
            RewritePlannedPostJob::class => 'не удалось подготовить текст',
            ReviewContentPlanJob::class => 'не удалось выполнить AI-проверку',
            DownloadMediaAssetJob::class => 'не удалось скачать медиа',
            PublishDeliveryJob::class => 'не удалось опубликовать материал',
            VerifySourceChannelAccessJob::class => 'не удалось проверить Telegram-источник',
            SyncSourceChannelStatisticsJob::class => 'не удалось синхронизировать источник',
            IngestTelegramUpdateJob::class => 'не удалось обработать Telegram-сообщение',
            DispatchDueDeliveriesJob::class => 'не удалось запустить публикации',
            default => 'фоновая задача завершилась с ошибкой',
        };
    }
}
