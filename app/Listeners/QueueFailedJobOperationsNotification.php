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
use App\Models\MediaAsset;
use App\OperationsNotificationTopic;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use Throwable;

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

        $downloadContext = $jobName === DownloadMediaAssetJob::class
            ? $this->downloadContext($event)
            : null;
        $error = Str::squish(
            $downloadContext['error'] ?? $event->exception->getMessage(),
        );
        $details = [
            'Очередь: '.$event->connectionName.'/'.$event->job->getQueue(),
            'Задача: '.$jobName,
            'Ошибка: '.($error !== '' ? $error : $event->exception::class),
        ];

        if (filled($downloadContext['asset'] ?? null)) {
            $details[] = $downloadContext['asset'];
        }

        $this->queueOperationsNotification->handle(
            OperationsNotificationTopic::Failures,
            'Терминальный сбой: '.$this->label($jobName),
            $details,
            route('horizon.index', ['view' => 'failed']),
        );
    }

    /** @return array{error: string|null, asset: string|null}|null */
    private function downloadContext(JobFailed $event): ?array
    {
        $command = data_get($event->job->payload(), 'data.command');

        if (! is_string($command)) {
            return null;
        }

        try {
            $job = unserialize($command, [
                'allowed_classes' => [DownloadMediaAssetJob::class],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $job instanceof DownloadMediaAssetJob) {
            return null;
        }

        $asset = MediaAsset::query()
            ->with('originMediaAsset.sourceMessage.source')
            ->find($job->mediaAssetId);

        if ($asset === null) {
            return null;
        }

        $origin = $asset->originMediaAsset ?? $asset;
        $lastError = data_get(
            $origin->metadata,
            $job->previewOnly
                ? 'preview_download_last_error'
                : 'download_last_error',
        );
        $message = is_array($lastError) && is_string($lastError['message'] ?? null)
            ? $lastError['message']
            : null;
        $accountId = is_array($lastError) ? ($lastError['telegram_account_id'] ?? null) : null;
        $sourceMessage = $origin->sourceMessage;
        $sourceMessageId = $origin->source_message_id;
        $sourceId = $sourceMessageId === null
            ? null
            : $sourceMessage->source_id;

        return [
            'error' => $message,
            'asset' => 'Медиа: #'.$origin->id
                .', сообщение: '.($sourceMessageId ?? '—')
                .', источник: '.($sourceId ?? '—')
                .', аккаунт: '.($accountId ?? '—'),
        ];
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
