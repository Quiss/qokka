<?php

namespace App\Console\Commands;

use App\Contracts\OperationsNotifier;
use App\OperationsNotificationTopic;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:operations:test
    {topic=content-plans : Топик для проверки: content-plans или failures}')]
#[Description('Send a test operations notification to the configured Telegram forum topic')]
class TestOperationsTelegramNotificationCommand extends Command
{
    public function handle(OperationsNotifier $notifier): int
    {
        $topic = OperationsNotificationTopic::tryFrom((string) $this->argument('topic'));

        if ($topic === null) {
            $this->error('Допустимые топики: content-plans, failures.');

            return self::INVALID;
        }

        try {
            $notifier->send(
                $topic,
                'Тест уведомлений Qokka',
                [
                    'Топик: '.$topic->value,
                    'Время: '.now()->format('d.m.Y H:i:s'),
                ],
                route('filament.admin.pages.dashboard'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Не удалось отправить уведомление: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Тестовое уведомление отправлено.');

        return self::SUCCESS;
    }
}
