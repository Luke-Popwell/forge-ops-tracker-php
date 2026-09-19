<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Reports the current request as one session, crash-free unless an unhandled exception (or a 5xx
 * response) actually affected it. Named the same as the other clients' equivalent class for
 * consistency across ports, but not a real aggregator the way those are: PHP-FPM's shared-nothing-
 * per-request model rules out an in-process background flusher the same way it already ruled one
 * out for DeliveryQueue (see that class's own doc comment), so there's no persistent process to
 * aggregate counts across many requests in the first place. Instead, this reports one degenerate
 * one-request "aggregate" per request (sessions_count: 1, crashed_sessions_count: 0 or 1),
 * deferred past the response the same way DeliveryQueue defers event delivery: one request, one
 * checkin, not a true windowed aggregate. More HTTP calls and no batching than the other clients
 * get, stated plainly rather than hidden behind matching names.
 */
class SessionFlusher
{
    private bool $shutdownRegistered = false;

    /** @var array<string, mixed>|null */
    private ?array $pending = null;

    public function __construct(
        private Configuration $configuration,
        private Client $client,
    ) {
    }

    public function recordSession(bool $crashed): void
    {
        if (!$this->configuration->trackSessions || !$this->configuration->isEnabled()) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pending = [
            'release' => $this->configuration->release,
            'environment' => $this->configuration->environment,
            'period_started_at' => $now,
            'period_ended_at' => $now,
            'sessions_count' => 1,
            'crashed_sessions_count' => $crashed ? 1 : 0,
        ];

        $this->ensureShutdownHandlerRegistered();
    }

    /**
     * Delivers the pending checkin, if any. The shutdown callback below just calls this; it's
     * public for the same reason DeliveryQueue::flush() is: a long-running CLI worker can call it
     * explicitly after each unit of work, rather than only ever getting a real flush at final
     * process exit.
     */
    public function flush(): void
    {
        if ($this->pending === null) {
            return;
        }

        $payload = $this->pending;
        $this->pending = null;

        try {
            $this->client->deliverSessionCheckin($payload);
        } catch (\Throwable $e) {
            $this->configuration->log(
                '[forge-ops-tracker] session flush error: ' . get_class($e) . ': ' . $e->getMessage()
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
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $this->flush();
        });
    }
}
