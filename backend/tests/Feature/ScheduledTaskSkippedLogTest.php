<?php

namespace Tests\Feature;

use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** A scheduler skip (e.g. stale withoutOverlapping mutex) leaves a warning in the log. */
class ScheduledTaskSkippedLogTest extends TestCase
{
    public function test_a_skipped_task_is_logged_by_name(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Scheduled task skipped: [invoices:expire]'));

        $event = app(Schedule::class)->command('invoices:expire')->withoutOverlapping(10);

        Event::dispatch(new ScheduledTaskSkipped($event));
    }
}
