<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * What this client knows about the request (or other unit of work started with
 * ForgeOpsTracker::startTrace()) currently running: which trace it belongs to, which remote span
 * called it (if any), what it's called, and whether it errored. Held in a plain static property on
 * ForgeOpsTracker for the same shared-nothing reason as the breadcrumb trail, created by
 * startTrace() and cleared by finishTrace().
 *
 * Exists whether or not span tracing is on: the trace id is also what an error event carries so
 * ForgeOps can link it to errors other services reported for the same trace, which works with
 * trackTracing off entirely. Mirrors gems/forge_ops_tracker's request_state.rb.
 */
final class RequestContext
{
    public ?string $transactionName = null;

    /**
     * "<HTTP method> <route pattern>", e.g. "GET /orders/{id}"; never the literal path. Either the
     * value itself or a Closure that works it out, only called the first time an error actually
     * needs it (see endpoint()).
     */
    private string|\Closure|null $endpoint = null;

    private bool $errored = false;

    public function __construct(
        public readonly string $traceId,
        public readonly ?string $parentSpanId = null,
    ) {
    }

    /**
     * Continues the caller's trace when $traceparent is a usable W3C header value, remembering the
     * caller's span as this request's remote parent; starts a fresh trace otherwise.
     */
    public static function fromTraceparent(?string $traceparent): self
    {
        $incoming = TraceParent::parse($traceparent);
        if ($incoming === null) {
            return new self(TraceParent::generateTraceId());
        }

        return new self($incoming['trace_id'], $incoming['parent_span_id']);
    }

    /**
     * A Closure is for a value that's costly to work out and usually never needed, like Symfony's
     * route path (see ForgeOpsTrackerPerformanceListener): it's resolved once, on first use.
     *
     * @param string|(\Closure(): ?string)|null $endpoint
     */
    public function setEndpoint(string|\Closure|null $endpoint): void
    {
        $this->endpoint = $endpoint;
    }

    public function endpoint(): ?string
    {
        if ($this->endpoint instanceof \Closure) {
            try {
                $this->endpoint = ($this->endpoint)();
            } catch (\Throwable) {
                $this->endpoint = null;
            }
        }

        return $this->endpoint;
    }

    public function markErrored(): void
    {
        $this->errored = true;
    }

    public function errored(): bool
    {
        return $this->errored;
    }
}
