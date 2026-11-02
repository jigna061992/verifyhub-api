<?php

use App\Models\NotificationLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');
Schedule::command('kyc:expire-cases')->everyFiveMinutes();
Schedule::command('verifications:expire-pending')->everyFiveMinutes();

// Old notifications are deleted after 90 days
Schedule::call(fn () => NotificationLog::where('created_at', '<', now()->subDays(90))->delete())->daily();
