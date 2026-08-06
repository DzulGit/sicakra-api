<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Console\Commands\GenerateTagihanBulanan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(GenerateTagihanBulanan::class)->dailyAt('01:00');
