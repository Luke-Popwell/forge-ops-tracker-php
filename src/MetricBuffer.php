<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Collects individual captureMetric()/captureInfrastructureMetric() calls and delivers them as one
 * batch after the response has been sent, the same shutdown-function shape PerformanceFlusher and
 * SpanFlusher use (see PerformanceFlusher's doc comment for why PHP has no background timer).
 * Unlike PerformanceFlusher this keeps a list of individually meaningful entries instead of summing
 * them into buckets: a customer's own signup or payment is exactly the kind of thing they will want
 * a genuinely accurate count/sum of later, so the server stores one row per entry as-is. Ported from
 * gems/forge_ops_tracker's metric_buffer.rb and infrastructure_metric_buffer.rb, which are the same
 * class twice; here it is one class instantiated twice, told which delivery function to use.
 *
 * Differences from the Ruby buffers: the buffer is capped at MAX_ENTRIES and drops further entries
 * once full (a plan without the feature answers 403 on every flush, and a long-running CLI worker
 * would otherwise grow the buffer without bound), and a NaN or infinite value is dropped at record
 * time (json_encode fails on one, and one bad entry would make the whole batch fail to send). A
 * failed delivery keeps every entry for the next flush(), which matters for a long-running worker;
 * a normal request's shutdown flush is its only one.
 */
class MetricBuffer
{
    public const MAX_ENTRIES = 1000;

    private bool $shutdownRegistered = false;

    /** @var array<int, array<string, mixed>> */
    private array $entries = [];

    /** @param callable(array<int, array<string, mixed>>): bool $deliver */
    public function __construct(
        private Configuration $configuration,
        private $deliver,
    ) {
    }

    /**
     * Adds one entry (everything but recorded_at, which is stamped here); returns whether it was kept.
     *
     * @param array<string, mixed> $entry
     */
    public function record(array $entry): bool
    {
        $value = $entry['value'] ?? null;
        if (!(is_int($value) || is_float($value)) || !is_finite((float) $value)) {
            $this->configuration->log('[forge-ops-tracker] dropped a metric with a non-finite value');

            return false;
        }
        if (count($this->entries) >= self::MAX_ENTRIES) {
            $this->configuration->log('[forge-ops-tracker] metric buffer full, dropping a metric');

            return false;
        }

        $entry['recorded_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->entries[] = $entry;
        $this->ensureShutdownHandlerRegistered();

        return true;
    }

    /** Delivers everything buffered so far as one batch. A failed delivery keeps every entry. */
    public function flush(): void
    {
        if ($this->entries === []) {
            return;
        }

        $snapshot = $this->entries;
        try {
            $delivered = ($this->deliver)($snapshot);
        } catch (\Throwable $e) {
            $this->configuration->log('[forge-ops-tracker] metric flush error: ' . get_class($e) . ': ' . $e->getMessage());

            return;
        }
        if ($delivered) {
            // Exactly the entries just delivered, not the whole list.
            $this->entries = array_slice($this->entries, count($snapshot));
        }
    }

    /** @return int how many entries are currently buffered */
    public function count(): int
    {
        return count($this->entries);
    }

    private function ensureShutdownHandlerRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->flush();
        });
    }
}
