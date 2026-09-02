<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Defers delivery until after the response has already been sent to the
 * visiting user, so a broken or slow tracker never adds latency they'd
 * notice. This is deliberately *not* a background thread + bounded queue
 * the way the Ruby/.NET/Python clients' DeliveryQueue is -- a typical PHP
 * request (PHP-FPM or similar) is single-threaded and shared-nothing
 * between requests, so there's no persistent worker to start in the
 * first place. Named the same as the other clients' equivalent class for
 * consistency across ports, not because the mechanism matches.
 *
 * Uses register_shutdown_function() + fastcgi_finish_request() (when
 * available) instead: the shutdown callback runs after the script would
 * otherwise have ended, and fastcgi_finish_request() flushes the
 * response to the client first, under PHP-FPM specifically -- so the
 * actual HTTP call(s) to ForgeOps happen after the user's connection has
 * already been served. This is the standard, idiomatic substitute real
 * PHP error trackers use for the same problem. Outside FPM (plain CLI,
 * for instance, where fastcgi_finish_request() doesn't exist at all),
 * delivery still happens in the shutdown function, just without that
 * "already sent" guarantee.
 */
class DeliveryQueue
{
    private bool $shutdownRegistered = false;

    /** @var array<int, array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private Configuration $configuration,
        private Client $client,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function push(array $payload): bool
    {
        if (count($this->pending) >= max(1, $this->configuration->queueSize)) {
            $this->configuration->log('[forge-ops-tracker] delivery queue full, dropping event');

            return false;
        }

        $this->pending[] = $payload;
        $this->ensureShutdownHandlerRegistered();

        return true;
    }

    /**
     * Delivers everything queued so far. The shutdown callback below just
     * calls this; it's public so a long-running CLI worker (which might
     * process many units of work in one process, long before it actually
     * exits) can flush explicitly after each one, rather than only ever
     * getting a real flush at final process exit.
     */
    public function flush(): void
    {
        foreach ($this->pending as $payload) {
            try {
                $this->client->deliver($payload);
            } catch (\Throwable $e) {
                // Per-item, not wrapping the whole loop: one bad delivery
                // must not stop every event queued after it in the same
                // batch.
                $this->configuration->log(
                    '[forge-ops-tracker] delivery worker error: ' . get_class($e) . ': ' . $e->getMessage()
                );
            }
        }

        $this->pending = [];
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
