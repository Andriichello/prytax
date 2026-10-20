<?php

use Core\Models\AccessToken;
use Core\Models\AuditLog;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', [
    '--model' => [AccessToken::class, AuditLog::class],
])->daily();
