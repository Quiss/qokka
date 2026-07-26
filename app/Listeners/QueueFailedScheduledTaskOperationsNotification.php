<?php

namespace App\Listeners;

use App\Actions\QueueOperationsNotification;
use App\OperationsNotificationTopic;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Support\Str;

class QueueFailedScheduledTaskOperationsNotification
{
    public function __construct(
        private readonly QueueOperationsNotification $queueOperationsNotification,
    ) {}

    public function handle(ScheduledTaskFailed $event): void
    {
        $error = Str::squish($event->exception->getMessage());

        $this->queueOperationsNotification->handle(
            OperationsNotificationTopic::Failures,
            'Терминальный сбой планировщика',
            [
                'Задача: '.$event->task->getSummaryForDisplay(),
                'Ошибка: '.($error !== '' ? $error : $event->exception::class),
            ],
            route('filament.admin.pages.dashboard'),
        );
    }
}
