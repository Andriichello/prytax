<?php

use Core\Models\AccessToken;
use Core\Models\AuditLog;
use Illuminate\Support\Facades\Schedule;
use PryTax\Models\IdempotencyKey;

Schedule::command('model:prune', [
    '--model' => [AccessToken::class, AuditLog::class, IdempotencyKey::class],
])->daily();

// Rates of the day for the receipts of the day, deadlines and ЄСВ months, then the notifications that follow them
Schedule::command('prytax:fetch-rates')->dailyAt('01:00');
Schedule::command('prytax:schedule-tax-periods')->dailyAt('01:10');
Schedule::command('prytax:sync-alerts')->dailyAt('01:20');
