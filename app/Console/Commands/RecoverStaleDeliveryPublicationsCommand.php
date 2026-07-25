<?php

namespace App\Console\Commands;

use App\Actions\RecoverStaleDeliveryPublications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deliveries:recover-stale')]
#[Description('Move stale Telegram deliveries from publishing to manual review')]
class RecoverStaleDeliveryPublicationsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecoverStaleDeliveryPublications $recoverStaleDeliveryPublications): int
    {
        $recovered = $recoverStaleDeliveryPublications->handle();

        if ($recovered > 0) {
            $this->warn("Recovered {$recovered} stale publishing deliveries for manual review.");
        }

        return self::SUCCESS;
    }
}
