<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');
Schedule::command('kyc:expire-cases')->everyFiveMinutes();
Schedule::command('verifications:expire-pending')->everyFiveMinutes();