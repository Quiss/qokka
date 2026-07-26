<?php

namespace App\Console\Commands;

use App\Actions\AdvanceContentPlanSafetyNet;
use App\Models\Publication;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('content-plans:run-safety-net')]
#[Description('Advance overdue content plans using the strict automatic safety policy')]
class RunContentPlanSafetyNetCommand extends Command
{
    public function handle(AdvanceContentPlanSafetyNet $advanceContentPlan): int
    {
        Publication::query()
            ->where('is_active', true)
            ->where('safety_net_enabled', true)
            ->chunkById(100, function ($publications) use ($advanceContentPlan): void {
                foreach ($publications as $publication) {
                    $advanceContentPlan->handle($publication);
                }
            });

        return self::SUCCESS;
    }
}
