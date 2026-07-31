<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:backup --only-db')
    ->dailyAt('01:00')
    ->withoutOverlapping(180)
    ->onOneServer();
Schedule::command('backup:clean')
    ->dailyAt('02:00')
    ->withoutOverlapping(180)
    ->onOneServer();
Schedule::command('backup:monitor')
    ->dailyAt('03:00')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('03:30')
    ->onOneServer();
Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')
    ->dailyAt('03:45')
    ->onOneServer();
Schedule::command('auth:clear-resets')
    ->dailyAt('04:00')
    ->onOneServer();
