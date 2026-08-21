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
        '24h' => ['label' => 'Last 24 hours', 'sub' => 'last 24h', 'since' => '-24 hours'],
        '7d'  => ['label' => 'Last 7 days', 'sub' => 'last 7d', 'since' => '-7 days'],
        '30d' => ['label' => 'Last 30 days', 'sub' => 'last 30d', 'since' => '-30 days'],
        '90d' => ['label' => 'Last 90 days', 'sub' => 'last 90d', 'since' => '-90 days'],
    ];

    private const INTERVALS = ['1h' => 'Per hour', '1d' => 'Per day'];

    private const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(
        private readonly MetricsDashboardQuery $query,
    ) {}

    public function overview(Request $request): View
    {
        $range    = (string) $request->query('range', '24h');
        $isCustom = $range === 'custom';
        $range    = ($isCustom || isset(self::RANGES[$range])) ? $range : '24h';

        $interval = (string) $request->query('interval', '');
        $interval = isset(self::INTERVALS[$interval]) ? $interval : ($range === '24h' ? '1h' : '1d');

        $filters = [
            'interval' => $interval,
            'timezone' => $this->timezone(),
        ];

        if ($isCustom) {
            foreach (['from', 'to'] as $key) {
                $value = $request->query($key);

                if (is_string($value) && $value !== '') {
                    $filters[$key] = $value;
                }
            }
        } else {
            $now             = Carbon::now();
            $filters['from'] = $now->copy()->modify(self::RANGES[$range]['since'])->toIso8601String();
            $filters['to']   = $now->toIso8601String();
        }

        $error     = null;
        $data      = ['total' => 0, 'aggs' => []];
        $functions = [];

        try {
            $data      = $this->query->overview($filters);
            $functions = $this->query->functions($filters);
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.overview', compact('data', 'filters', 'range', 'interval', 'error', 'functions') + [
            'ranges'    => self::RANGES,
            'intervals' => self::INTERVALS,
            'timezone'  => $filters['timezone'],
        ]);
    }

    public function transactions(Request $request): View
    {
        $filters = $this->filters($request);
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', config('elastic_audit_metrics.dashboard.per_page', 25));
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 25;
        $error   = null;
        $data    = ['hits' => [], 'total' => 0];

        try {
            $data = $this->query->transactions(
                [...$filters, 'timezone' => $this->timezone()],
                $page,
                $perPage,
            );
        } catch (Throwable $exception) {
            $error = $this->queryError($exception);
        }

        return view('elastic-audit::metrics.transactions', compact('data', 'filters', 'page', 'perPage', 'error') + [
            'timezone'       => $this->timezone(),
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
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

        return view('elastic-audit::metrics.trace', compact('items', 'traceId', 'error') + [
            'timezone' => $this->timezone(),
        ]);
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

        return view('elastic-audit::metrics.profile', compact('profile', 'error') + [
            'timezone' => $this->timezone(),
        ]);
    }

    private function timezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
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
