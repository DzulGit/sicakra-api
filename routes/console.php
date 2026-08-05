<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


use App\Console\Commands\GenerateTagihanBulanan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(GenerateTagihanBulanan::class)->monthlyOn(20, '01:00');