<?php

use App\Models\LogSistema;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [LogSistema::class]])->dailyAt('03:00');

Schedule::command('erp:sincroniza')->everyMinute()->withoutOverlapping()->runInBackground();
