<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tsitsishvili\ElasticAudit\Dashboard\ActivityDashboardQuery;
use Tsitsishvili\ElasticAudit\Dashboard\DashboardAccess;
use Tsitsishvili\ElasticAudit\Dashboard\HttpLogDashboardQuery;

/** Resolves a pasted trace ID, traceparent, request ID or event ID to the page that shows it. */
final class TraceSearchController
{
    private const TRACEPARENT = '/^[0-9a-f]{2}-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/i';

    private const TRACE_ID = '/^[0-9a-f]{32}$/i';

    private const SOURCES = [
        DashboardAccess::HTTP     => ['show' => 'http-logs.logs.show', 'index' => 'http-logs.logs.index'],
        DashboardAccess::ACTIVITY => ['show' => 'activity-logs.logs.show', 'index' => 'activity-logs.logs.index'],
    ];

    public function __invoke(
        Request $request,
        HttpLogDashboardQuery $http,
        ActivityDashboardQuery $activity,
        DashboardAccess $access,
    ): Response {
        $id = trim((string) $request->query('id', ''));

        if ($id === '') {
            return redirect()->route('elastic-audit-metrics.overview');
        }

        if (preg_match(self::TRACEPARENT, $id, $matches) === 1) {
            $id = $matches[1];
        }

        if (preg_match(self::TRACE_ID, $id) === 1) {
            return redirect()->route('elastic-audit-metrics.traces.show', strtolower($id));
        }

        // Only sources the viewer could open themselves are searched; a match never leads into a closed dashboard.
        $found = [];

        foreach ([DashboardAccess::HTTP => $http, DashboardAccess::ACTIVITY => $activity] as $source => $query) {
            if (! $access->allows($request, $source)) {
                continue;
            }

            $docs = $query->search(['request_id' => $id], 1, 25)['hits'];

            if (($event = $query->find($id)) !== null && ! in_array($event['event_id'] ?? null, array_column($docs, 'event_id'), true)) {
                $docs[] = $event;
            }

            foreach ($docs as $doc) {
                $found[] = ['source' => $source, 'doc' => $doc];
            }
        }

        $traced = array_filter($found, static fn (array $match): bool => is_string($match['doc']['trace']['id'] ?? null));
        usort($traced, static fn (array $a, array $b): int => strcmp((string) ($a['doc']['@timestamp'] ?? ''), (string) ($b['doc']['@timestamp'] ?? '')));

        if ($traced !== []) {
            return redirect()->route('elastic-audit-metrics.traces.show', strtolower($traced[0]['doc']['trace']['id']));
        }

        if ($found !== []) {
            $source = $found[0]['source'];
            $inside = array_filter($found, static fn (array $match): bool => $match['source'] === $source);

            return count($inside) === 1
                ? redirect()->route(self::SOURCES[$source]['show'], $found[0]['doc']['event_id'])
                : redirect()->route(self::SOURCES[$source]['index'], ['request_id' => $id]);
        }

        return response()->view('elastic-audit::metrics.search-miss', ['id' => $id], 404);
    }
}
