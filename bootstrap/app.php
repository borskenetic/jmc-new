<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedulerLog = storage_path('logs/scheduler.log');

        $schedule->command('attendance:close-stale-ins')
            ->dailyAt('00:05')
            ->timezone('Asia/Manila');

        // Drain pending gate SMS when the modem queue was full (503).
        $schedule->command('sms:retry-pending --failed-503')
            ->everyMinute()
            ->timezone('Asia/Manila')
            ->withoutOverlapping()
            ->appendOutputTo($schedulerLog);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
