<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work (run by the `scheduler` container via schedule:work)
|--------------------------------------------------------------------------
*/

// SPEC §5: move expired invoices to expired / partially_paid and fire webhooks.
Schedule::command('invoices:expire')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// SPEC §6.2: retry schedule 1m, 5m, 30m, 2h, 6h, 24h.
Schedule::command('webhooks:retry')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Mark a network unhealthy if the watcher heartbeat is older than 2 minutes.
Schedule::command('networks:health')
    ->everyMinute()
    ->withoutOverlapping();
