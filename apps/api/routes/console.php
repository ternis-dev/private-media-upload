<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Löschkonzept: expired shares purged nightly (<24h); unscanned assets hourly.
Schedule::command('pwf:purge')->daily();
Schedule::command('pwf:quarantine')->hourly();
