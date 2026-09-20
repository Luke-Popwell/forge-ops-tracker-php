<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Reports the current request's own duration, and every kind alongside it (a database query, a
 * queue job), as one small batch of performance samples. Named the same as the other clients'
 * equivalent class for consistency across ports, but not a real bucketing aggregator the way
 * those are: same reasoning SessionFlusher's own doc comment documents (PHP-FPM's
 * shared-nothing-per-request model rules out an in-process background flusher that spans many
 * requests), so each entry here is its own degenerate one-occurrence "sample" (request_count: 1,
 * duration_sum_ms/max_duration_ms both the one measured duration), deferred past the response the
 * same way SessionFlusher does. More HTTP calls and no cross-request batching than the other
 * clients get, stated plainly rather than hidden behind a matching name; several samples *within*
 * one request (the controller timing plus one per query) do still deliver together as one batch,
 * the same shape deliverPerformanceSamples() was always built for.
 */
class PerformanceFlusher
{
    private bool $shutdownRegistered = false;

    /** @var array<int, array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private Configuration $configuration,
        private Client $client,
    ) {
    }

    public function record(string $transactionName, float $durationMs, string $kind = 'controller'): void
    {
        if (!$this->configuration->trackPerformance || !$this->configuration->isEnabled()) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pending[] = [
            'transaction_name' => $transactionName,
            'kind' => $kind,
            'environment' => $this->configuration->environment,
            'release' => $this->configuration->release,
            'period_started_at' => $now,
            'period_ended_at' => $now,
            'request_count' => 1,
            'duration_sum_ms' => $durationMs,
            'max_duration_ms' => $durationMs,
            // One occurrence, so exactly one bucket with a count of 1 (see HistogramBucketer).
            // PHP turns the numeric-string label into an int array key, which json_encode still
            // writes back out as an object key ("50"), never a list, since a real label is never 0.
            'histogram' => [HistogramBucketer::bucketFor($durationMs) => 1],
        ];

        $this->ensureShutdownHandlerRegistered();
    }

    /**
     * Delivers every pending sample, if any, as one batch. Public for the same reason
     * SessionFlusher::flush() is: a long-running CLI worker (a queue job, see
     * ForgeOpsTrackerQueueListener) can call it explicitly after each unit of work, since that
     * kind of process's own eventual shutdown is not a meaningful per-unit-of-work boundary the
     * way one HTTP request's shutdown already is.
     */
    public function flush(): void
    {
        if ($this->pending === []) {
            return;
        }

        $samples = $this->pending;
        $this->pending = [];

        try {
            $this->client->deliverPerformanceSamples($samples);
        } catch (\Throwable $e) {
            $this->configuration->log(
                '[forge-ops-tracker] performance flush error: ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    private function ensureShutdownHandlerRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            // Safe to call again even if SessionFlusher's own shutdown function already called
            // it for this same request: fastcgi_finish_request() is a documented no-op once the
            // connection has already been finished, not an error.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->flush();
        });
    }
}
