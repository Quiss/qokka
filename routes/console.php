<?php

use App\Jobs\DispatchDueDeliveriesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('content-plans:generate-due')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('telegram:accounts:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('telegram:sources:sync-statistics')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('source-posts:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
Schedule::command('deliveries:recover-stale')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::job(new DispatchDueDeliveriesJob, 'publish')->everyMinute()->withoutOverlapping()->onOneServer();
