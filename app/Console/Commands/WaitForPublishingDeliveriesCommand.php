<?php

namespace App\Console\Commands;

use App\Actions\RecoverStaleDeliveryPublications;
use App\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deliveries:wait-for-publishing {--timeout=620 : Maximum number of seconds to wait}')]
#[Description('Wait for active Telegram publications to finish before deployment')]
class WaitForPublishingDeliveriesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecoverStaleDeliveryPublications $recoverStaleDeliveryPublications): int
    {
        $timeout = max(0, (int) $this->option('timeout'));
        $startedAt = microtime(true);

        while (true) {
            $recovered = $recoverStaleDeliveryPublications->handle();

            if ($recovered > 0) {
                $this->warn("Moved {$recovered} stale publishing deliveries to manual review.");
            }

            $publishingCount = Delivery::query()
                ->where('status', DeliveryStatus::Publishing)
                ->count();

            if ($publishingCount === 0) {
                $this->info('No active Telegram publications remain.');

                return self::SUCCESS;
            }

            if ((microtime(true) - $startedAt) >= $timeout) {
                $this->error("Timed out waiting for {$publishingCount} active Telegram publications.");

                return self::FAILURE;
            }

            sleep(1);
        }
    }
}
