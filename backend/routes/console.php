<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 6 (database-design.md §12): withoutOverlapping() guards against a
// slow run (e.g. a Stripe API hiccup) still executing when the next
// minute's invocation fires — the sweep is otherwise safe to run
// concurrently with itself (each candidate is locked/re-verified
// independently), but overlap serves no purpose and only adds contention.
Schedule::command('payments:expire-stale')->everyMinute()->withoutOverlapping();
