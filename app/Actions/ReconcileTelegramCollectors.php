<?php

namespace App\Actions;

use App\Jobs\VerifySourceChannelAccessJob;
use App\Models\Source;
use App\SourceType;

class ReconcileTelegramCollectors
{
    public function __construct(private readonly AssignTelegramCollector $assignTelegramCollector) {}

    /** @param (callable(): bool)|null $shouldStop */
    public function handle(?callable $shouldStop = null): int
    {
        $reassigned = 0;
        $shouldStop ??= static fn (): bool => false;

        Source::query()
            ->where('type', SourceType::Telegram)
            ->where('is_active', true)
            ->with([
                'collectorTelegramAccount',
                'preferredCollectorTelegramAccount',
                'telegramAccounts',
            ])
            ->orderBy('id')
            ->each(function (Source $source) use (&$reassigned, $shouldStop): ?bool {
                if ($shouldStop()) {
                    return false;
                }

                $current = $source->collectorTelegramAccount;
                $isCurrentUsable = $current !== null
                    && $current->isCollectorReady()
                    && $source->hasAvailableAccessFor($current->id);
                $preferred = $source->preferredCollectorTelegramAccount;
                $isPreferredUsable = $preferred !== null
                    && $preferred->isCollectorReady()
                    && $source->hasAvailableAccessFor($preferred->id);
                $shouldRetryPreferred = $preferred !== null
                    && $preferred->isCollectorReady()
                    && $source->shouldRetryPreferredCollectorSubscription();
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
                    VerifySourceChannelAccessJob::dispatch($source->id)->onQueue('telegram');
                }

                $previousId = $source->collector_telegram_account_id;
                $selected = $this->assignTelegramCollector->handle(
                    $source,
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
