<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Tsitsishvili\ElasticAudit\Services\MetricsRecorder;

/**
 * Explicit timing for application code.
 *
 * Profiles answer "what was hot in this one sampled request". These spans answer
 * "how long does this operation take, across every request" — they are recorded
 * for every call, nest inside the surrounding transaction, and aggregate by name
 * on the performance dashboard.
 *
 * @method static mixed measure(string $name, Closure $callback, string $type = 'app.function')
 * @method static ?string beginMeasure(string $name, string $type = 'app.function')
 * @method static void endMeasure(?string $token, string $name, string $outcome = 'success')
 *
 * @see MetricsRecorder
 */
class Performance extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MetricsRecorder::class;
    }
}
