<?php

namespace App\Actions;

use App\Models\SourceChannel;
use App\TelegramAccountStatus;

class ReconcileTelegramCollectors
{
    public function __construct(private readonly AssignTelegramCollector $assignTelegramCollector) {}

    public function handle(): int
    {
        $reassigned = 0;

        SourceChannel::query()
            ->where('is_active', true)
            ->with('collectorTelegramAccount')
            ->orderBy('id')
            ->each(function (SourceChannel $sourceChannel) use (&$reassigned): void {
                $current = $sourceChannel->collectorTelegramAccount;
                $isHealthy = $current !== null
                    && $current->is_active
                    && $current->status === TelegramAccountStatus::Connected
                    && $current->isHeartbeatFresh();

                if ($isHealthy) {
                    return;
                }

                $previousId = $sourceChannel->collector_telegram_account_id;
                $selected = $this->assignTelegramCollector->handle($sourceChannel);

                if ($selected?->id !== $previousId) {
                    $reassigned++;
                }
            });

        return $reassigned;
    }
}
