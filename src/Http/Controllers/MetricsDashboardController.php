<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Tsitsishvili\ElasticAudit\Dashboard\MetricsDashboardQuery;

final class MetricsDashboardController
{
    private const RANGES = [
        '24h' => ['label' => 'Last 24 hours', 'since' => '-24 hours'],
        '7d'  => ['label' => 'Last 7 days', 'since' => '-7 days'],
        '30d' => ['label' => 'Last 30 days', 'since' => '-30 days'],
    ];

    public function __construct(
        private readonly MetricsDashboardQuery $query,
    ) {}

    public function overview(Request $request): View
    {
        $range   = (string) $request->query('range', '24h');
        $range   = isset(self::RANGES[$range]) ? $range : '24h';
        $now     = Carbon::now();
        $filters = [
            'from'     => $now->copy()->modify(self::RANGES[$range]['since'])->toIso8601String(),
            'to'       => $now->toIso8601String(),
            'interval' => $range === '24h' ? '1h' : '1d',
            'timezone' => (string) (config('app.timezone') ?: 'UTC'),
        ];
        $error = null;
        $data  = ['total' => 0, 'aggs' => []];

        try {
            $data = $this->query->overview($filters);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.overview', compact('data', 'filters', 'range', 'error') + [
            'ranges' => self::RANGES,
        ]);
    }

    public function transactions(Request $request): View
    {
        $filters = $this->filters($request);
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', config('elastic_audit_metrics.dashboard.per_page', 25));
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $error   = null;
        $data    = ['hits' => [], 'total' => 0];

        try {
            $data = $this->query->transactions(
                [...$filters, 'timezone' => (string) (config('app.timezone') ?: 'UTC')],
                $page,
                $perPage,
            );
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.transactions', compact('data', 'filters', 'page', 'perPage', 'error'));
    }

    public function trace(string $traceId): View
    {
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $traceId) === 1, 404);
        $error = null;
        $items = [];

        try {
            $items = $this->query->trace($traceId);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        abort_if($items === [] && $error === null, 404);

        return view('elastic-audit::metrics.trace', compact('items', 'traceId', 'error'));
    }

    public function profile(string $profileId): View
    {
        $error   = null;
        $profile = null;

        try {
            $profile = $this->query->profile($profileId);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        abort_if($profile === null && $error === null, 404);

        return view('elastic-audit::metrics.profile', compact('profile', 'error'));
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['type', 'outcome', 'service', 'trace_id', 'from', 'to'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    private function queryError(Throwable $exception): string
    {
        Log::error('Elastic Audit metrics dashboard query failed', ['error' => $exception->getMessage()]);

        return 'Failed to query Elasticsearch. Check the application log for details.';
    }
}
