<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Comando de ejemplo de Laravel.
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Revisar las reservas vencidas cada minuto.
Schedule::command('reservations:expire')
    ->everyMinute()
    ->withoutOverlapping();
