<?php

namespace App\Actions;

use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;

class ReconcileTelegramCollectors
{
    public function __construct(private readonly AssignTelegramCollector $assignTelegramCollector) {}

    public function handle(): int
    {
        $reassigned = 0;

        SourceChannel::query()
            ->where('is_active', true)
            ->with([
                'collectorTelegramAccount',
                'preferredCollectorTelegramAccount',
                'telegramAccounts',
            ])
            ->orderBy('id')
            ->each(function (SourceChannel $sourceChannel) use (&$reassigned): void {
                $current = $sourceChannel->collectorTelegramAccount;
                $isCurrentUsable = $current !== null
                    && $current->isCollectorReady()
                    && $sourceChannel->hasAvailableAccessFor($current->id);
                $preferred = $sourceChannel->preferredCollectorTelegramAccount;
                $isPreferredUsable = $preferred !== null
                    && $preferred->isCollectorReady()
                    && $sourceChannel->hasAvailableAccessFor($preferred->id);
                $shouldRetryPreferred = $preferred !== null
                    && $preferred->isCollectorReady()
                    && $sourceChannel->shouldRetryPreferredCollectorSubscription();
                $shouldKeepCurrent = $isCurrentUsable
                    && (
                        $preferred === null
                        || $current->is($preferred)
                        || (! $isPreferredUsable && ! $shouldRetryPreferred)
                    );

                if ($shouldKeepCurrent) {
                    return;
                }

                if ($shouldRetryPreferred) {
                    VerifySourceChannelAccessJob::dispatch($sourceChannel->id)->onQueue('telegram');
                }

                $previousId = $sourceChannel->collector_telegram_account_id;
                $selected = $this->assignTelegramCollector->handle(
                    $sourceChannel,
                    ensureCurrentSubscription: false,
                    clearWhenUnavailable: false,
                );

                if ($selected !== null && $selected->id !== $previousId) {
                    $reassigned++;
                }
            });

        return $reassigned;
    }
}
