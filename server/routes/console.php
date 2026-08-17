<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Now that tokens expire, the expired rows have to be swept or
 * personal_access_tokens grows forever and the devices screen keeps counting
 * dead sessions as active. Requires `php artisan schedule:work` (or a cron
 * entry) to actually run.
 */
Schedule::command('sanctum:prune-expired --hours=24')->daily();
