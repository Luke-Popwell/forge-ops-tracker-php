<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Public entry point:
 *
 *     ForgeOpsTracker::init(dsn: "https://<api_key>@getforgeops.net/api/v1/events");
 *
 * See the README for Laravel/Symfony integration and what gets captured
 * automatically vs. what needs an explicit captureException() call.
 */
final class ForgeOpsTracker
{
    private static ?Configuration $configuration = null;
    private static ?Reporter $reporter = null;
    private static ?SessionFlusher $sessionFlusher = null;
    private static ?PerformanceFlusher $performanceFlusher = null;
    private static ?SpanFlusher $spanFlusher = null;
    private static ?MetricBuffer $metricBuffer = null;
    private static ?MetricBuffer $infrastructureMetricBuffer = null;

    /** The current trace, if one is open: a plain static property for the same reason $breadcrumbs is. */
    private static ?SpanBuffer $trace = null;
    private static bool $exceptionHandlerInstalled = false;

    /**
     * The affected user set via setUser() below, if any. A plain static property, not a thread-
     * local the way gems/forge_ops_tracker's own equivalent needs to be: PHP-FPM's shared-
     * nothing-per-request model (see PerformanceFlusher's own comment) already resets every
     * static property at the end of each request, the exact same reason $configuration itself is
     * safely a static property too.
     * @var array<string, mixed>|null
     */
    private static ?array $currentUser = null;

    /**
     * The current breadcrumb trail, if one is active. A plain static property for the same reason
     * $currentUser above already is (see BreadcrumbBuffer's own doc comment for the full
     * reasoning), lazily created by breadcrumbBuffer() below the first time anything actually adds
     * to it, so a plain CLI script or a queued job with no HTTP request wrapping it at all still
     * gets a working buffer with no setup needed.
     */
    private static ?BreadcrumbBuffer $breadcrumbs = null;

    /** @var (callable(Throwable): void)|null */
    private static $previousExceptionHandler = null;

    /** @param string[]|null $enabledEnvironments */
    public static function init(
        ?string $dsn = null,
        ?string $environment = null,
        ?string $release = null,
        ?string $serverName = null,
        ?string $appRoot = null,
        ?array $enabledEnvironments = null,
        ?int $queueSize = null,
        ?float $timeout = null,
        ?bool $scrubPii = null,
        ?bool $captureSourceContext = null,
        ?bool $captureSqlObjects = null,
        ?bool $captureSqlStatement = null,
        ?bool $trackSessions = null,
        ?bool $trackPerformance = null,
        ?bool $trackBreadcrumbs = null,
        ?int $maxBreadcrumbs = null,
        ?bool $trackTracing = null,
        ?float $traceCaptureThreshold = null,
        ?bool $installExceptionHandler = null,
        mixed $logger = null,
    ): Configuration {
        $configuration = self::configuration();

        if ($dsn !== null) {
            $configuration->dsn = $dsn;
        }
        if ($environment !== null) {
            $configuration->environment = $environment;
        }
        if ($release !== null) {
            $configuration->release = $release;
        }
        if ($serverName !== null) {
            $configuration->serverName = $serverName;
        }
        if ($appRoot !== null) {
            $configuration->appRoot = $appRoot;
        }
        if ($enabledEnvironments !== null) {
            $configuration->enabledEnvironments = $enabledEnvironments;
        }
        if ($queueSize !== null) {
            $configuration->queueSize = $queueSize;
        }
        if ($timeout !== null) {
            $configuration->timeout = $timeout;
        }
        if ($scrubPii !== null) {
            $configuration->scrubPii = $scrubPii;
        }
        if ($captureSourceContext !== null) {
            $configuration->captureSourceContext = $captureSourceContext;
        }
        if ($captureSqlObjects !== null) {
            $configuration->captureSqlObjects = $captureSqlObjects;
        }
        if ($captureSqlStatement !== null) {
            $configuration->captureSqlStatement = $captureSqlStatement;
        }
        if ($trackSessions !== null) {
            $configuration->trackSessions = $trackSessions;
        }
        if ($trackPerformance !== null) {
            $configuration->trackPerformance = $trackPerformance;
        }
        if ($trackBreadcrumbs !== null) {
            $configuration->trackBreadcrumbs = $trackBreadcrumbs;
        }
        if ($maxBreadcrumbs !== null) {
            $configuration->maxBreadcrumbs = $maxBreadcrumbs;
        }
        if ($trackTracing !== null) {
            $configuration->trackTracing = $trackTracing;
        }
        if ($traceCaptureThreshold !== null) {
            $configuration->traceCaptureThreshold = $traceCaptureThreshold;
        }
        if ($logger !== null) {
            $configuration->logger = $logger;
        }

        if ($installExceptionHandler ?? true) {
            self::installExceptionHandler();
        }

        return $configuration;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $user defaults to whatever setUser() last set; pass one
     *     explicitly to override that for this one report.
     */
    public static function captureException(Throwable $throwable, array $context = [], ?array $user = null): void
    {
        self::reporter()->report($throwable, $context, $user ?? self::$currentUser, self::currentBreadcrumbs());
    }

    /**
     * Manually attaches an affected user to whatever gets reported for the rest of this request
     * (there's no automatic Laravel/Symfony auth detection yet; call this from middleware,
     * a service provider, wherever the current user is available). id/email/username are all
     * independently optional; call with no arguments to clear whatever was set.
     */
    public static function setUser(?string $id = null, ?string $email = null, ?string $username = null): void
    {
        $user = array_filter(['id' => $id, 'email' => $email, 'username' => $username], static fn ($value) => $value !== null);
        self::$currentUser = $user === [] ? null : $user;
    }

    /**
     * Starts a fresh, empty breadcrumb trail, discarding whatever was there before. Called by
     * ForgeOpsTrackerBreadcrumbMiddleware (Laravel) and ForgeOpsTrackerBreadcrumbListener (Symfony)
     * at the start of every request, and by ForgeOpsTrackerQueueListener at the start of every
     * queue job attempt, not something app code normally calls directly.
     *
     * Plain PHP-FPM would reset the static property on its own between requests anyway (see
     * $breadcrumbs's own doc comment), but this reset is not redundant there either: without it, a
     * request would inherit whatever breadcrumbs a *previous, unrelated* unit of work on the same
     * long-running process left behind under Laravel Octane (Swoole/RoadRunner) or a queue worker,
     * both of which keep the whole application, statics included, alive across many requests/jobs
     * in one process, the same reason ForgeOpsTrackerUserContextMiddleware clears $currentUser in
     * a finally rather than relying on the process itself resetting it.
     */
    public static function startBreadcrumbTrail(): void
    {
        self::$breadcrumbs = new BreadcrumbBuffer(self::configuration());
    }

    /**
     * Clears the current breadcrumb trail entirely (not just an empty one: back to null, so the
     * next addBreadcrumb() call lazily creates a fresh buffer rather than reusing this one).
     * Called at the end of every request/job, in a finally, for the identical Octane/queue-worker
     * leak reason startBreadcrumbTrail()'s own doc comment gives; see
     * ForgeOpsTrackerBreadcrumbMiddleware/ForgeOpsTrackerBreadcrumbListener/
     * ForgeOpsTrackerQueueListener for where.
     */
    public static function endBreadcrumbTrail(): void
    {
        self::$breadcrumbs = null;
    }

    /**
     * Records one breadcrumb from an automatic source (a query, the request/controller lifecycle,
     * a queue job run). Called by the Laravel/Symfony integrations, not something app code
     * normally calls directly: matches recordPerformance()'s own shape (a plain public static
     * method integrations call, documented rather than language-enforced as integration-only,
     * since PHP has no way to restrict a static method to callers in a different namespace). Host
     * app code wants addBreadcrumb() below instead.
     *
     * Gated on trackBreadcrumbs independently of trackPerformance, checked here rather than at
     * each individual call site, the same "each call site checks its own flag" pattern
     * gems/forge_ops_tracker's own railtie.rb uses, just centralized in this one method since every
     * automatic source already funnels through it.
     *
     * @param array<string, mixed> $data
     */
    public static function recordBreadcrumb(string $category, string $message, string $level = 'info', array $data = []): void
    {
        $configuration = self::configuration();
        if (!$configuration->trackBreadcrumbs || !$configuration->isEnabled()) {
            return;
        }

        self::breadcrumbBuffer()->add($category, $message, $level, $data);
    }

    /**
     * Adds one breadcrumb to the current trail by hand, regardless of whether the automatic
     * sources above are on: trackBreadcrumbs only gates recordBreadcrumb(), never this manual call,
     * the same "manual API isn't gated by it" contract gems/forge_ops_tracker's own
     * ForgeOpsTracker.add_breadcrumb documents. Works outside a request entirely too (a plain CLI
     * script, a queue job with no listener registered at all): breadcrumbBuffer() below lazily
     * creates a buffer on demand, the same "works standalone, no specific setup required" shape
     * setUser() already has.
     *
     * @param array<string, mixed> $data
     */
    public static function addBreadcrumb(string $message, string $category = 'custom', string $level = 'info', array $data = []): void
    {
        self::breadcrumbBuffer()->add($category, $message, $level, $data);
    }

    /**
     * The current trail's entries, read by captureException() when building the outgoing payload.
     * Never throws, never null: an empty array when nothing is currently tracking breadcrumbs at
     * all (no middleware/listener registered, trackBreadcrumbs off, or simply nothing added yet).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function currentBreadcrumbs(): array
    {
        return self::$breadcrumbs?->all() ?? [];
    }

    /**
     * Records the current request as one session, crash-free unless $crashed is true. Called by
     * the Laravel middleware and Symfony listener, not something app code normally calls directly.
     */
    public static function recordSession(bool $crashed): void
    {
        self::sessionFlusher()->recordSession($crashed);
    }

    /**
     * Records one performance sample: the current request's own duration, a database query's, or
     * a queue job's ($kind: 'controller'/'query'/'job'). Called by the Laravel middleware/queue
     * listener and Symfony listener, not something app code normally calls directly.
     */
    public static function recordPerformance(string $transactionName, float $durationMs, string $kind = 'controller'): void
    {
        self::performanceFlusher()->record($transactionName, $durationMs, $kind);
    }

    /**
     * Delivers whatever performance samples are currently pending, without waiting for the
     * request/process to end. Called explicitly by ForgeOpsTrackerQueueListener after each queue
     * job, since a worker process's own eventual shutdown isn't a meaningful per-job boundary the
     * way one HTTP request's shutdown already is; see PerformanceFlusher::flush()'s own comment.
     */
    public static function flushPerformance(): void
    {
        self::performanceFlusher()->flush();
    }

    /**
     * Records a named business metric (a signup, a payment, anything you want to name), buffered and
     * delivered as one batch after the response has been sent. $value defaults to 1.0 for a bare
     * counter; pass one for a real magnitude (`captureMetric('payment', 49.0)`); it may be negative
     * (a refund). A no-op when the client isn't enabled (no DSN, or this environment isn't in
     * enabledEnvironments), and a NaN or infinite value is dropped. Nothing here is automatic, so
     * there is no trackMetrics flag.
     */
    public static function captureMetric(string $name, float $value = 1.0): void
    {
        $configuration = self::configuration();
        if (!$configuration->isEnabled()) {
            return;
        }

        self::metricBuffers()[0]->record([
            'metric_name' => $name,
            'value' => $value,
            'environment' => $configuration->environment,
            'release' => $configuration->release,
        ]);
    }

    /**
     * Records one infrastructure reading (CPU, memory, disk, anything else a script of yours reads)
     * from one of your own hosts. $hostname defaults to Configuration::$serverName, so a script
     * running on the box it reports about needs no argument. Same buffered-batch delivery and
     * no-op-when-disabled contract as captureMetric(). A CLI cron job's shutdown function flushes it
     * when the script ends normally; call flushMetrics() if it ends with exit() inside a shutdown
     * handler or a kill.
     */
    public static function captureInfrastructureMetric(string $name, float $value, ?string $hostname = null): void
    {
        $configuration = self::configuration();
        if (!$configuration->isEnabled()) {
            return;
        }

        self::metricBuffers()[1]->record([
            'metric_name' => $name,
            'value' => $value,
            'hostname' => $hostname ?? $configuration->serverName ?? '',
        ]);
    }

    /** Delivers every buffered metric and infrastructure reading right now, without waiting for the script to end. */
    public static function flushMetrics(): void
    {
        self::$metricBuffer?->flush();
        self::$infrastructureMetricBuffer?->flush();
    }

    /** @return array{0: MetricBuffer, 1: MetricBuffer} */
    private static function metricBuffers(): array
    {
        if (self::$metricBuffer === null) {
            $configuration = self::configuration();
            $client = new Client($configuration);
            self::$metricBuffer = new MetricBuffer($configuration, [$client, 'deliverMetrics']);
            self::$infrastructureMetricBuffer = new MetricBuffer($configuration, [$client, 'deliverInfrastructureMetrics']);
        }

        return [self::$metricBuffer, self::$infrastructureMetricBuffer];
    }

    /**
     * Starts a fresh trace, discarding any earlier one. Called by the Laravel middleware and
     * Symfony listener at the start of every request; a no-op when trackTracing is off or the
     * client isn't enabled. Not something app code normally calls directly.
     */
    public static function startTrace(): void
    {
        $configuration = self::configuration();
        self::$trace = $configuration->trackTracing && $configuration->isEnabled() ? new SpanBuffer($configuration) : null;
    }

    /**
     * Ends the current trace, queueing it for delivery when the root took at least
     * traceCaptureThreshold, and always clears it (back to null, so nothing leaks into the next
     * request on a long-running worker). $startedAt is a microtime(true) value.
     */
    public static function finishTrace(string $rootName, float $startedAt, float $durationMs): void
    {
        $trace = self::$trace;
        self::$trace = null;
        $payload = $trace?->finishTrace($rootName, $startedAt, $durationMs);
        if ($payload !== null) {
            self::spanFlusher()->push($payload);
        }
    }

    /**
     * Times $work as a child span of whatever span is open (or of the request's root), returning
     * its result. Outside a trace it just runs $work. Recorded even if $work throws, which is
     * rethrown unchanged.
     *
     *     $order = ForgeOpsTracker::span('charge card', fn () => $gateway->charge($id), 'service', ['order' => $id]);
     *
     * @template T
     * @param callable(): T $work
     * @param array<string, mixed> $data
     * @return T
     */
    public static function span(string $name, callable $work, string $kind = 'service', array $data = []): mixed
    {
        $trace = self::$trace;
        if ($trace === null) {
            return $work();
        }

        $id = $trace->open();
        $startedAt = microtime(true);
        try {
            return $work();
        } finally {
            $trace->finish($id, $name, $kind, $startedAt, (microtime(true) - $startedAt) * 1000, $data);
        }
    }

    /**
     * Records a span you timed yourself under the current one; a no-op outside a trace.
     * $startedAt is a microtime(true) value.
     *
     * @param array<string, mixed> $data
     */
    public static function recordSpan(string $name, string $kind, float $startedAt, float $durationMs, array $data = []): void
    {
        self::$trace?->recordLeaf($name, $kind, $startedAt, $durationMs, $data);
    }

    /** Delivers every pending trace now, e.g. after each queue job in a long-running worker. */
    public static function flushSpans(): void
    {
        self::spanFlusher()->flush();
    }

    public static function configuration(): Configuration
    {
        if (self::$configuration === null) {
            self::$configuration = new Configuration();
        }

        return self::$configuration;
    }

    private static function reporter(): Reporter
    {
        if (self::$reporter === null) {
            $configuration = self::configuration();
            $client = new Client($configuration);
            $deliveryQueue = new DeliveryQueue($configuration, $client);
            self::$reporter = new Reporter($configuration, new EventBuilder($configuration), $deliveryQueue);
        }

        return self::$reporter;
    }

    private static function sessionFlusher(): SessionFlusher
    {
        if (self::$sessionFlusher === null) {
            $configuration = self::configuration();
            self::$sessionFlusher = new SessionFlusher($configuration, new Client($configuration));
        }

        return self::$sessionFlusher;
    }

    private static function spanFlusher(): SpanFlusher
    {
        if (self::$spanFlusher === null) {
            $configuration = self::configuration();
            self::$spanFlusher = new SpanFlusher($configuration, new Client($configuration));
        }

        return self::$spanFlusher;
    }

    private static function performanceFlusher(): PerformanceFlusher
    {
        if (self::$performanceFlusher === null) {
            $configuration = self::configuration();
            self::$performanceFlusher = new PerformanceFlusher($configuration, new Client($configuration));
        }

        return self::$performanceFlusher;
    }

    /**
     * Lazily creates a breadcrumb buffer if nothing has started one yet (see addBreadcrumb()'s own
     * doc comment for why that matters standalone), otherwise returns the current one as-is:
     * unlike configuration()/reporter()/etc. above, this is not "create once, forever" caching,
     * since startBreadcrumbTrail()/endBreadcrumbTrail() both deliberately replace $breadcrumbs
     * out from under this method between requests/jobs.
     */
    private static function breadcrumbBuffer(): BreadcrumbBuffer
    {
        if (self::$breadcrumbs === null) {
            self::$breadcrumbs = new BreadcrumbBuffer(self::configuration());
        }

        return self::$breadcrumbs;
    }

    /**
     * Reports anything that would otherwise crash the script outright (a
     * plain CLI script, an Artisan/Symfony console command) with no
     * further wiring: the same "unhandled needs no wiring" case the
     * Laravel/Symfony integrations cover for web requests. Still calls
     * whatever handler was already installed afterward, so it never
     * changes program behavior. This does *not* catch a web request's
     * unhandled exception under a real app server: Laravel/Symfony
     * catch that themselves, long before it would ever reach here, which
     * is what those integrations are for.
     */
    private static function installExceptionHandler(): void
    {
        if (self::$exceptionHandlerInstalled) {
            return;
        }
        self::$exceptionHandlerInstalled = true;

        self::$previousExceptionHandler = set_exception_handler(static function (Throwable $e): void {
            self::captureException($e);
            if (self::$previousExceptionHandler !== null) {
                (self::$previousExceptionHandler)($e);
            }
        });
    }

    /** @internal not part of the public API: resets static state between test cases */
    public static function resetForTesting(): void
    {
        if (self::$previousExceptionHandler !== null) {
            set_exception_handler(self::$previousExceptionHandler);
        } else {
            restore_exception_handler();
        }

        self::$configuration = null;
        self::$reporter = null;
        self::$sessionFlusher = null;
        self::$performanceFlusher = null;
        self::$spanFlusher = null;
        self::$metricBuffer = null;
        self::$infrastructureMetricBuffer = null;
        self::$trace = null;
        self::$exceptionHandlerInstalled = false;
        self::$previousExceptionHandler = null;
        self::$currentUser = null;
        self::$breadcrumbs = null;
    }
}
