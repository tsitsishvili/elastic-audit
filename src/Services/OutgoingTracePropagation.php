<?php

declare(strict_types=1);

namespace Tsitsishvili\ElasticAudit\Services;

use Psr\Http\Message\RequestInterface;
use Tsitsishvili\ElasticAudit\DataTransferObjects\MetricData;
use Tsitsishvili\ElasticAudit\Support\TraceContext;
use WeakMap;

final class OutgoingTracePropagation
{
    /** @var WeakMap<RequestInterface, string> */
    private WeakMap $spanIds;

    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {
        $this->spanIds = new WeakMap;
    }

    public function inject(RequestInterface $request): RequestInterface
    {
        if (! (bool) config('elastic_audit_metrics.trace.propagate_http', true)) {
            return $request;
        }

        $existing = TraceContext::fromTraceParent(
            $request->getHeaderLine('traceparent'),
            $request->getHeaderLine('tracestate'),
        );
        $context = $this->metrics->propagationContext();

        if ($existing->traceId !== null && $existing->spanId !== null) {
            if ($context !== null && $existing->traceId === $context->traceId) {
                $this->spanIds[$request] = $existing->spanId;
            }

            return $request;
        }

        if ($context === null) {
            return $request;
        }

        $spanId      = MetricData::randomId(8);
        $traceParent = $context->toTraceParent($spanId, $context->sampled);

        if ($traceParent === null) {
            return $request;
        }

        $request = $request->withHeader('traceparent', $traceParent);

        if ($context->traceState !== null && ! $request->hasHeader('tracestate')) {
            $request = $request->withHeader('tracestate', $context->traceState);
        }

        $this->spanIds[$request] = $spanId;

        return $request;
    }

    public function spanId(RequestInterface $request): ?string
    {
        return $this->spanIds[$request] ?? null;
    }
}
