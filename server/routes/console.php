<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Every five minutes, so a request is cancelled close to its collection time.
// Overlap-safe, and each cancellation re-checks the order under a lock.
Schedule::command('advance:expire-unpaid')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('advance:notify-due')->hourly();

// Lapsed holds already stop counting on their own; this tidies them and tells
// open menus. Every five minutes keeps the 0.1 CPU instance quiet.
Schedule::command('stock:expire-holds')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('guest:prune-tokens')->daily();

Schedule::command('guest:prune-carts')->daily();
