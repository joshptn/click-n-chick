<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('otp:prune')->daily();

Schedule::command('advance:expire-unpaid')->everyFiveMinutes()->withoutOverlapping(10);

Schedule::command('advance:notify-due')->hourly();

Schedule::command('stock:expire-holds')->everyFiveMinutes()->withoutOverlapping(10);

// The 10-minute payment window (FR-04.5) is enforced to the minute here, and at
// once whenever the customer checks on the order.
Schedule::command('payments:expire-checkouts')->everyMinute()->withoutOverlapping(5);

Schedule::command('guest:prune-tokens')->daily();

Schedule::command('guest:prune-carts')->daily();

// Cheap when the file is fresh; a new container fetches it within ten minutes.
Schedule::command('geoip:update')->everyTenMinutes()->withoutOverlapping(30);
