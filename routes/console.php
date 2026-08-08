<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Console\Commands\GenerateTagihanBulanan;
use App\Console\Commands\TagihanReminderH3Command;
use Illuminate\Support\Facades\Schedule;

Schedule::command(GenerateTagihanBulanan::class)->dailyAt('01:00');
Schedule::command(TagihanReminderH3Command::class)->dailyAt('07:00');
