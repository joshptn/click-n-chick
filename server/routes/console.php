<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('advance:expire-unpaid')->hourly();

Schedule::command('advance:notify-due')->hourly();

Schedule::command('guest:prune-tokens')->daily();

Schedule::command('guest:prune-carts')->daily();
