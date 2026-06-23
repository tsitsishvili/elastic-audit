<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\ElasticAudit\Http\Controllers\HttpLogDashboardController;

Route::get('/', [HttpLogDashboardController::class, 'overview'])->name('overview');
Route::get('/logs', [HttpLogDashboardController::class, 'index'])->name('logs.index');
Route::get('/logs/{eventId}', [HttpLogDashboardController::class, 'show'])->name('logs.show');
