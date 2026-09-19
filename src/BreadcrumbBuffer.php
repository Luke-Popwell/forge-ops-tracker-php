<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * A bounded, in-order trail of whatever happened recently in the current request (or, outside a
 * request entirely, the rest of whatever this PHP process is currently doing: a CLI script, a
 * queue job): SQL queries, the request/controller lifecycle, queue job runs, plus anything added
 * by hand via ForgeOpsTracker::addBreadcrumb(). Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/breadcrumb_buffer.rb: the same ring-buffer contract
 * (capped at Configuration::$maxBreadcrumbs, oldest entry dropped once full), read back by
 * EventBuilder when an error is reported.
 *
 * Unlike the Ruby gem (Thread.current) or the Python client (a contextvars.ContextVar, needed
 * there for correctness under Celery's own thread-pool workers and any future asyncio/ASGI
 * integration), this buffer is held in a single instance behind a plain static property on
 * ForgeOpsTracker, exactly the same "shared-nothing-per-request" reasoning
 * ForgeOpsTracker::$currentUser already documents: a typical PHP request (PHP-FPM or similar) is
 * single-threaded, one process per request, so there is no concurrent-request isolation problem
 * for a static property to solve in the first place, unlike Ruby's thread pool or Python's worker
 * processes. See ForgeOpsTracker::$breadcrumbs and startBreadcrumbTrail()/endBreadcrumbTrail() for
 * how a request (or a queue job) actually gets, and loses, a fresh buffer, including the Laravel
 * Octane/Swoole/RoadRunner long-running-worker case where that reset genuinely matters, not just
 * plain PHP-FPM.
 */
final class BreadcrumbBuffer
{
    /** @var array<int, array<string, mixed>> */
    private array $entries = [];

    public function __construct(private Configuration $configuration)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function add(string $category, string $message, string $level = 'info', array $data = []): void
    {
        // Read fresh on every add, not captured once at construction: the same reasoning
        // DeliveryQueue re-reads Configuration::$queueSize on every push, so a config change made
        // via ForgeOpsTracker::init() (or a direct write to the Configuration object) takes effect
        // on whatever's added next, not just a buffer created afterward.
        $maxSize = max($this->configuration->maxBreadcrumbs, 0);
        if ($maxSize === 0) {
            return;
        }

        $this->entries[] = [
            'category' => $category,
            'message' => $message,
            'level' => $level,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ];

        $overflow = count($this->entries) - $maxSize;
        if ($overflow > 0) {
            array_splice($this->entries, 0, $overflow);
        }
    }

    /**
     * Returns every entry currently in the buffer, oldest first. No explicit defensive copy is
     * needed the way Ruby's own #all (Array#dup) or Python's own .all() (list()) each need one:
     * PHP arrays are value types (copy-on-write), so a caller mutating the returned array can
     * never reach back into this buffer's own internal state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
