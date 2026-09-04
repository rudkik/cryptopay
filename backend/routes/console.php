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

/*
 * Every task below passes an explicit expiry to withoutOverlapping(). The
 * default is 1440 minutes: a scheduler container that is SIGKILLed mid-run
 * (a deploy, an OOM) never releases its lock, and invoice expiry — the thing
 * that closes out unpaid invoices and fires their webhooks — would then stay
 * silently blocked for a full day. A few minutes is longer than any of these
 * runs can plausibly take and short enough that the schedule heals itself.
 */

// SPEC §5: move expired invoices to expired / partially_paid and fire webhooks.
Schedule::command('invoices:expire')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// SPEC §6.2: retry schedule 1m, 5m, 30m, 2h, 6h, 24h.
Schedule::command('webhooks:retry')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Mark a network unhealthy if the watcher heartbeat is older than 2 minutes.
Schedule::command('networks:health')
    ->everyMinute()
    ->withoutOverlapping(5);
