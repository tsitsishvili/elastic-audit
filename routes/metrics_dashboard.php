<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tsitsishvili\ElasticAudit\Http\Controllers\MetricsDashboardController;
use Tsitsishvili\ElasticAudit\Http\Controllers\TraceSearchController;

Route::get('/', [MetricsDashboardController::class, 'overview'])->name('overview');
Route::get('/transactions', [MetricsDashboardController::class, 'transactions'])->name('transactions');
Route::get('/functions', [MetricsDashboardController::class, 'functions'])->name('functions');
Route::get('/find', TraceSearchController::class)->name('find');
Route::get('/traces/{traceId}', [MetricsDashboardController::class, 'trace'])->name('traces.show');
Route::get('/profiles/{profileId}', [MetricsDashboardController::class, 'profile'])->name('profiles.show');
