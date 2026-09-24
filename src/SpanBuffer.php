<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * One request's (or one queue job's) worth of spans, sharing a single trace id. Held in a plain
 * static property on ForgeOpsTracker, the same shared-nothing reasoning BreadcrumbBuffer's own doc
 * comment gives. Nesting comes from a stack of open span ids: a span started while another is
 * open becomes its child, and anything else parents under the root.
 *
 * The trace id and remote parent span id come from the request's RequestContext (see
 * ForgeOpsTracker::startTrace()), so a trace this sends and an error event from the same request
 * always agree on which trace they belong to. When the request continued another service's trace,
 * the root span's parent_span_id is that service's span, which ForgeOps treats as a remote parent.
 */
final class SpanBuffer
{
    public const MAX_SPANS = 500;

    /** The kinds the ingestion API accepts; anything else would fail validation for the whole trace. */
    private const KINDS = ['controller', 'service', 'database', 'redis', 'http', 'job', 'other'];

    private string $traceId;
    private string $rootSpanId;
    private ?string $remoteParentSpanId;

    /** @var array<int, array<string, mixed>> */
    private array $spans = [];

    /** @var string[] */
    private array $openSpanIds = [];

    public function __construct(private Configuration $configuration, ?string $traceId = null, ?string $remoteParentSpanId = null)
    {
        $this->traceId = $traceId ?? TraceParent::generateTraceId();
        $this->rootSpanId = TraceParent::generateSpanId();
        $this->remoteParentSpanId = $remoteParentSpanId;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * Opens a span and returns its id; pair with finish(). $id is one generated beforehand, as
     * ForgeOpsTracker::httpSpan() does so its outgoing traceparent header can name the span.
     */
    public function open(?string $id = null): string
    {
        $id ??= TraceParent::generateSpanId();
        $this->openSpanIds[] = $id;

        return $id;
    }

    /** @param array<string, mixed> $data */
    public function finish(string $id, string $name, string $kind, float $startedAt, float $durationMs, array $data = []): void
    {
        $this->openSpanIds = array_values(array_filter($this->openSpanIds, static fn (string $open): bool => $open !== $id));
        $this->record($id, $name, $kind, $startedAt, $durationMs, $data);
    }

    /**
     * Records an already-finished span as a child of whatever is currently open.
     *
     * @param array<string, mixed> $data
     */
    public function recordLeaf(string $name, string $kind, float $startedAt, float $durationMs, array $data = []): void
    {
        $this->record(TraceParent::generateSpanId(), $name, $kind, $startedAt, $durationMs, $data);
    }

    /** @param array<string, mixed> $data */
    private function record(string $id, string $name, string $kind, float $startedAt, float $durationMs, array $data): void
    {
        if (count($this->spans) >= self::MAX_SPANS - 1) {
            return; // leave room for the root
        }

        $parent = $this->openSpanIds === [] ? $this->rootSpanId : $this->openSpanIds[array_key_last($this->openSpanIds)];
        $this->spans[] = $this->build($id, $parent, $name, $kind, $startedAt, $durationMs, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function build(string $id, ?string $parentId, string $name, string $kind, float $startedAt, float $durationMs, array $data): array
    {
        return [
            'span_id' => $id,
            'parent_span_id' => $parentId,
            'name' => $name,
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'other',
            'started_at' => self::timestamp($startedAt),
            'duration_ms' => round($durationMs, 2),
            'environment' => $this->configuration->environment,
            'release' => $this->configuration->release,
            'data' => (object) $data,
        ];
    }

    /** ISO 8601 with milliseconds, UTC, from a microtime(true) style float. */
    public static function timestamp(float $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s', (int) floor($seconds)) . sprintf('.%03dZ', (int) floor(($seconds - floor($seconds)) * 1000));
    }

    /**
     * The wire payload once the trace is over: the root span plus everything recorded beneath it,
     * or null when the root was faster than Configuration::$traceCaptureThreshold and the request
     * didn't error. An errored request's trace is always sent, however fast it was, since the
     * waterfall of what led up to an error is exactly what an issue page wants to show next to it.
     *
     * @return array<string, mixed>|null
     */
    public function finishTrace(string $rootName, float $startedAt, float $durationMs, bool $errored = false): ?array
    {
        if (!$errored && $durationMs < $this->configuration->traceCaptureThreshold * 1000) {
            return null;
        }

        return [
            'trace_id' => $this->traceId,
            'spans' => array_merge([$this->build($this->rootSpanId, $this->remoteParentSpanId, $rootName, 'controller', $startedAt, $durationMs, [])], $this->spans),
        ];
    }
}
