<?php

namespace App\Console\Commands;

use App\Actions\QueueContentPlanGeneration;
use App\Models\ContentPlan;
use App\Models\Publication;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('content-plans:generate-due')]
#[Description('Create tomorrow content plans for publications whose planning time is due')]
class GenerateDueContentPlansCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(QueueContentPlanGeneration $queueContentPlanGeneration): int
    {
        Publication::query()->where('is_active', true)->each(function (Publication $publication) use ($queueContentPlanGeneration): void {
            $localNow = CarbonImmutable::now($publication->timezone);

            if ($localNow->format('H:i') < substr($publication->planning_time, 0, 5)) {
                return;
            }

            $plan = ContentPlan::query()->firstOrCreate([
                'publication_id' => $publication->id,
                'plan_date' => $localNow->addDay()->toDateString(),
            ]);

            if ($queueContentPlanGeneration->handle($plan)) {
                $this->info("Queued content plan {$plan->id} for {$publication->name}.");
            }
        });

        return self::SUCCESS;
    }
}
