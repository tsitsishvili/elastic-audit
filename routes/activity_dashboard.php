<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\ElasticAudit\Http\Controllers\ActivityDashboardController;

Route::get('/', [ActivityDashboardController::class, 'overview'])->name('overview');
Route::get('/logs', [ActivityDashboardController::class, 'index'])->name('logs.index');
Route::get('/logs/{eventId}', [ActivityDashboardController::class, 'show'])->name('logs.show');
