<?php

namespace App\Actions;

use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\SourceChannel;

class ReconcileTelegramCollectors
{
    public function __construct(private readonly AssignTelegramCollector $assignTelegramCollector) {}

    /** @param (callable(): bool)|null $shouldStop */
    public function handle(?callable $shouldStop = null): int
    {
        $reassigned = 0;
        $shouldStop ??= static fn (): bool => false;

        SourceChannel::query()
            ->where('is_active', true)
            ->with([
                'collectorTelegramAccount',
                'preferredCollectorTelegramAccount',
                'telegramAccounts',
            ])
            ->orderBy('id')
            ->each(function (SourceChannel $sourceChannel) use (&$reassigned, $shouldStop): ?bool {
                if ($shouldStop()) {
                    return false;
                }

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
                    return null;
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

                return null;
            });

        return $reassigned;
    }
}
