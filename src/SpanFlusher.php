<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Delivers finished traces to /spans after the response has been sent, the same shutdown-function
 * shape PerformanceFlusher has (see its doc comment for why PHP has no background queue). Each
 * trace is its own POST; bounded by Configuration::$queueSize.
 */
class SpanFlusher
{
    private bool $shutdownRegistered = false;

    /** @var array<int, array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private Configuration $configuration,
        private Client $client,
    ) {
    }

    /** @param array<string, mixed> $trace */
    public function push(array $trace): bool
    {
        if (count($this->pending) >= max(1, $this->configuration->queueSize)) {
            $this->configuration->log('[forge-ops-tracker] span queue full, dropping trace');

            return false;
        }

        $this->pending[] = $trace;
        $this->ensureShutdownHandlerRegistered();

        return true;
    }

    /** Delivers every pending trace; public so a long-running worker can flush after each unit of work. */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $trace) {
            try {
                $this->client->deliverSpans($trace);
            } catch (\Throwable $e) {
                $this->configuration->log('[forge-ops-tracker] span flush error: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
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
