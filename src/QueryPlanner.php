<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Opt-in EXPLAIN for slow PostgreSQL queries (Configuration::$explainSlowQueries, off by default).
 *
 * consider() runs on the request's own path, right after a query finished, and only decides:
 * enabled, PostgreSQL, at or over explainThresholdMs, a plain SELECT (QueryPlans::explainable()),
 * and within the rate limits (QueryPlanRateLimiter). A query that passes is held in memory with the
 * closure that will EXPLAIN it. Nothing runs until runPending(), which the Laravel integration calls
 * from its middleware's terminate(), after the response has been sent; a shutdown function (after
 * fastcgi_finish_request() under PHP-FPM) runs anything still pending as a fallback. PHP has no
 * background thread to hand this to, which is why it waits for the response instead.
 *
 * The closure runs EXPLAIN (FORMAT JSON), never EXPLAIN ANALYZE, with the original statement and
 * bindings on a new connection in a READ ONLY transaction with a 2 second statement_timeout, and
 * rolls it back. The payload holds only the masked statement and the masked plan, and goes out on
 * the normal DeliveryQueue. Any failure is logged and dropped; nothing here ever throws.
 */
class QueryPlanner
{
    public const MAX_PENDING = 10;

    /** @var list<array{explain: callable, masked: string, db_system: string, duration_ms: float, captured_at: float}> */
    private array $pending = [];

    private bool $shutdownRegistered = false;

    private static bool $explaining = false;

    public function __construct(
        private Configuration $configuration,
        private DeliveryQueue $deliveryQueue,
        private QueryPlanRateLimiter $limiter,
    ) {
    }

    /**
     * True while an EXPLAIN runs, so a query listener can ignore the EXPLAIN's own statements if
     * they ever reach it.
     */
    public static function isExplaining(): bool
    {
        return self::$explaining;
    }

    /**
     * @param callable(): mixed $explain runs EXPLAIN (FORMAT JSON) on a separate connection and
     *     returns the plan (the JSON text Postgres returns, or it decoded); only ever called from
     *     runPending()
     */
    public function consider(string $statement, ?string $dbSystem, float $durationMs, callable $explain, ?string $masked = null): bool
    {
        try {
            $configuration = $this->configuration;
            if (!$configuration->explainSlowQueries || !$configuration->isEnabled()) {
                return false;
            }
            if ($dbSystem !== 'postgresql' || $durationMs < $configuration->explainThresholdMs) {
                return false;
            }
            if (count($this->pending) >= self::MAX_PENDING || !QueryPlans::explainable($statement)) {
                return false;
            }
            $masked ??= SqlStatement::maskForSpan($statement);
            if ($masked === null || !$this->limiter->allow($masked)) {
                return false;
            }

            $this->pending[] = [
                'explain' => $explain,
                'masked' => $masked,
                'db_system' => $dbSystem,
                'duration_ms' => $durationMs,
                'captured_at' => microtime(true),
            ];
            $this->ensureShutdownHandlerRegistered();

            return true;
        } catch (Throwable $e) {
            $this->configuration->log('[forge-ops-tracker] query plan skipped: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /** How many EXPLAINs are waiting for runPending(). */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Runs every pending EXPLAIN and queues its plan for delivery. Clears the pending list first,
     * so the bindings are dropped even if something here fails, and a second call is a no-op.
     */
    public function runPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $job) {
            self::$explaining = true;
            try {
                $plan = ($job['explain'])();
                $payload = QueryPlans::payload($this->configuration, $job['masked'], $job['db_system'], $plan, $job['duration_ms'], $job['captured_at']);
            } catch (Throwable $e) {
                $this->configuration->log('[forge-ops-tracker] EXPLAIN failed: ' . get_class($e) . ': ' . $e->getMessage());

                continue;
            } finally {
                self::$explaining = false;
            }

            if ($payload === null) {
                $this->configuration->log('[forge-ops-tracker] query plan dropped: unusable or over ' . QueryPlans::MAX_PLAN_BYTES . ' bytes');

                continue;
            }
            $this->deliveryQueue->push($payload);
        }
    }

    private function ensureShutdownHandlerRegistered(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $this->shutdownRegistered = true;

        register_shutdown_function(function (): void {
            if ($this->pending === []) {
                return;
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $this->runPending();
            $this->deliveryQueue->flush();
        });
    }
}
