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
    private static ?DeliveryQueue $changeQueue = null;

    /**
     * The startup change snapshot waiting to be sent at shutdown (see startChangeSnapshot()), and
     * whether this process already scheduled one. Under PHP-FPM both reset with every request, which
     * is why ChangeSnapshot::send() also checks a marker file before actually sending anything.
     */
    private static ?ChangeSnapshot $pendingChangeSnapshot = null;
    private static bool $changeSnapshotScheduled = false;

    /** The current trace, if one is open: a plain static property for the same reason $breadcrumbs is. */
    private static ?SpanBuffer $trace = null;

    /**
     * The current request's trace id, remote parent, name and errored flag (see RequestContext), set
     * by startTrace() whether or not span tracing is on, cleared by finishTrace().
     */
    private static ?RequestContext $request = null;

    /**
     * Request context copied off onto an exception that escaped a framework integration (see
     * snapshotOnto()), for when the framework only reports it after finishTrace() and the
     * user/breadcrumb middleware have already cleared their state. A WeakMap keyed by the exception
     * itself: the exception is the one thing guaranteed to travel from there to wherever it's
     * finally reported, and a WeakMap leaves it exactly as the host app threw it (no dynamic
     * property, no wrapper) and lets it be garbage collected as usual.
     *
     * @var \WeakMap<Throwable, array<string, mixed>>|null
     */
    private static ?\WeakMap $snapshots = null;
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

    /**
     * @param string[]|null $enabledEnvironments
     * @param string[]|null $tracePropagationTargets see Configuration::$tracePropagationTargets; null
     *     leaves the current setting alone (every host, unless something already changed it)
     */
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
        ?bool $propagateTraces = null,
        ?array $tracePropagationTargets = null,
        ?bool $installExceptionHandler = null,
        mixed $logger = null,
        ?bool $detectChanges = null,
        ?bool $trackEnvVarNames = null,
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
        if ($propagateTraces !== null) {
            $configuration->propagateTraces = $propagateTraces;
        }
        if ($tracePropagationTargets !== null) {
            $configuration->tracePropagationTargets = $tracePropagationTargets;
        }
        if ($logger !== null) {
            $configuration->logger = $logger;
        }
        if ($detectChanges !== null) {
            $configuration->detectChanges = $detectChanges;
        }
        if ($trackEnvVarNames !== null) {
            $configuration->trackEnvVarNames = $trackEnvVarNames;
        }

        if ($installExceptionHandler ?? true) {
            self::installExceptionHandler();
        }

        self::startChangeSnapshot();

        return $configuration;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $user defaults to whatever setUser() last set; pass one
     *     explicitly to override that for this one report.
     *
     * During a request (between startTrace() and finishTrace()), the event also carries the
     * request's trace_id, transaction_name and endpoint, and the request is marked errored so its
     * trace is sent however fast it was. An exception a framework integration saw escape the
     * request falls back to the copy snapshotOnto() made of all of that (and of the user and
     * breadcrumb trail), for when the framework reports it only after the request's state is gone.
     */
    public static function captureException(Throwable $throwable, array $context = [], ?array $user = null): void
    {
        $snapshot = self::$snapshots[$throwable] ?? [];
        $request = self::$request;
        $request?->markErrored();

        $breadcrumbs = self::currentBreadcrumbs();
        if ($breadcrumbs === []) {
            $breadcrumbs = $snapshot['breadcrumbs'] ?? [];
        }

        self::reporter()->report(
            $throwable,
            $context,
            $user ?? self::$currentUser ?? $snapshot['user'] ?? null,
            $breadcrumbs,
            $request !== null ? $request->transactionName : $snapshot['transaction_name'] ?? null,
            $request !== null ? $request->endpoint() : $snapshot['endpoint'] ?? null,
            $request !== null ? $request->traceId : $snapshot['trace_id'] ?? null,
        );
    }

    /**
     * The current request's W3C trace id (32 lowercase hex characters), or null outside a request.
     * Handy for your own logs: it's the id ForgeOps links errors across services with.
     */
    public static function currentTraceId(): ?string
    {
        return self::$request?->traceId;
    }

    /**
     * Names the current request once the framework has matched its route: $transactionName is the
     * same name its performance sample and root span use, $endpoint the HTTP method plus the route
     * pattern ("GET /users/{id}"), or a Closure returning it when that's costly to work out (see
     * RequestContext::setEndpoint()). Called by the Laravel middleware and Symfony listener as early
     * as each framework allows, so an error reported partway through the controller already carries
     * both; a no-op outside a request. Not something app code normally calls directly.
     *
     * @param string|(\Closure(): ?string)|null $endpoint
     */
    public static function setRequestRoute(?string $transactionName, string|\Closure|null $endpoint): void
    {
        if (self::$request === null) {
            return;
        }

        self::$request->transactionName = $transactionName;
        self::$request->setEndpoint($endpoint);
    }

    /**
     * Copies the current request's trace id, transaction name and endpoint, plus the affected user
     * and breadcrumb trail, onto $throwable (see $snapshots) and marks the request errored. Called
     * by the Laravel middleware when an exception escapes the request, just before its own finally
     * clears that state, so a report the framework only makes afterward still has it. The first
     * snapshot wins if the same exception passes through twice: the innermost one saw the request
     * closest to where it failed. Never throws. Not something app code normally calls directly.
     */
    public static function snapshotOnto(Throwable $throwable): void
    {
        try {
            $request = self::$request;
            $request?->markErrored();

            self::$snapshots ??= new \WeakMap();
            if (isset(self::$snapshots[$throwable])) {
                return;
            }

            self::$snapshots[$throwable] = [
                'user' => self::$currentUser,
                'breadcrumbs' => self::currentBreadcrumbs(),
                'transaction_name' => $request?->transactionName,
                'endpoint' => $request?->endpoint(),
                'trace_id' => $request?->traceId,
            ];
        } catch (Throwable) {
            // A failure here must never replace the host app's own exception with one of ours.
        }
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
     * Records one thing that changed in a running system, so ForgeOps can show it next to the errors
     * and slowdowns that followed:
     *
     *     ForgeOpsTracker::recordChange('feature_flag', 'Enabled new_checkout for 10%', ['rollout' => 10]);
     *
     * $kind is one of feature_flag, config, migration, dependency, infrastructure, or other (anything
     * else is sent as "other"). $environment defaults to the configured one; $occurredAt defaults to
     * now; $id is an optional idempotency key. Queued and delivered after the response has been
     * sent, the same way error events are (see DeliveryQueue). A no-op when the client isn't
     * enabled. Never throws: returns false when nothing was queued.
     *
     * @param array<string, mixed> $details
     */
    public static function recordChange(
        string $kind,
        string $title,
        array $details = [],
        ?string $environment = null,
        ?string $service = null,
        ?string $actor = null,
        ?string $url = null,
        ?string $id = null,
        \DateTimeInterface|string|null $occurredAt = null,
    ): bool {
        try {
            $configuration = self::configuration();
            if (!$configuration->isEnabled()) {
                return false;
            }

            $payload = Change::build($configuration, $kind, $title, $details, $environment, $service, $actor, $url, $id, $occurredAt);
            if ($payload === null) {
                return false;
            }

            return self::changeQueue()->push($payload);
        } catch (Throwable $e) {
            self::configuration()->log('[forge-ops-tracker] recordChange failed: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Delivers every queued recordChange() call, and the startup change snapshot if it hasn't gone
     * yet, right now instead of at shutdown: e.g. after each job in a long-running queue worker.
     */
    public static function flushChanges(): void
    {
        self::$changeQueue?->flush();

        $snapshot = self::$pendingChangeSnapshot;
        self::$pendingChangeSnapshot = null;
        $snapshot?->send();
    }

    /**
     * Schedules the startup change snapshot (see ChangeSnapshot) for this process's shutdown, after
     * the response has been sent, so it never delays a request or a command. Called by init(); a
     * second call is a no-op, and so is a call while the client isn't enabled or detectChanges is
     * off (without using up the once). Never throws.
     */
    private static function startChangeSnapshot(): void
    {
        try {
            $configuration = self::configuration();
            if (self::$changeSnapshotScheduled || !$configuration->isEnabled() || !$configuration->detectChanges) {
                return;
            }
            self::$changeSnapshotScheduled = true;
            self::$pendingChangeSnapshot = new ChangeSnapshot($configuration, new Client($configuration));

            register_shutdown_function(static function (): void {
                if (self::$pendingChangeSnapshot === null) {
                    return;
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                self::flushChanges();
            });
        } catch (Throwable) {
            // A snapshot must never be able to break init().
        }
    }

    private static function changeQueue(): DeliveryQueue
    {
        if (self::$changeQueue === null) {
            $configuration = self::configuration();
            self::$changeQueue = new DeliveryQueue($configuration, new Client($configuration), 'deliverChange');
        }

        return self::$changeQueue;
    }

    /**
     * Starts a fresh trace, discarding any earlier one. Called by the Laravel middleware and
     * Symfony listener at the start of every request, with the request's own `traceparent` header:
     * a usable W3C value continues the caller's trace (same trace id, and the root span's parent is
     * the caller's span), anything else (null, blank, malformed) starts a new one. Not something
     * app code normally calls directly, except to trace work that isn't a request (see the README).
     *
     * The trace id exists whenever the client is enabled, even with trackTracing off, since errors
     * captured before finishTrace() carry it and httpSpan() propagates it; only span recording is
     * gated on trackTracing. A no-op when the client isn't enabled.
     */
    public static function startTrace(?string $traceparent = null): void
    {
        $configuration = self::configuration();
        if (!$configuration->isEnabled()) {
            self::$request = null;
            self::$trace = null;

            return;
        }

        self::$request = RequestContext::fromTraceparent($traceparent);
        self::$trace = $configuration->trackTracing
            ? new SpanBuffer($configuration, self::$request->traceId, self::$request->parentSpanId)
            : null;
    }

    /**
     * Ends the current trace, queueing it for delivery when the root took at least
     * traceCaptureThreshold or the request errored (an error was captured during it, or an
     * exception escaped it), and always clears it along with the request context (back to null, so
     * nothing leaks into the next request on a long-running worker). $startedAt is a
     * microtime(true) value.
     */
    public static function finishTrace(string $rootName, float $startedAt, float $durationMs): void
    {
        $trace = self::$trace;
        $errored = self::$request?->errored() ?? false;
        self::$trace = null;
        self::$request = null;
        $payload = $trace?->finishTrace($rootName, $startedAt, $durationMs, $errored);
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
     * Makes one outgoing HTTP call inside the current trace: records it as an "http" span named
     * "<METHOD> <host>" (never the path or query, which could carry an id or a token) and hands
     * $send the headers to add to the request, currently a W3C `traceparent` whose parent id is
     * that span's own id, so the called service's root span nests under it. Returns whatever $send
     * returns; the span is recorded even when $send throws, which is rethrown unchanged.
     *
     *     $response = ForgeOpsTracker::httpSpan('POST', $url, fn (array $headers) => Http::withHeaders($headers)->post($url, $body));
     *
     * The headers are empty outside a trace, when propagateTraces is off, or when the host isn't
     * in tracePropagationTargets; outside a trace no span is recorded either. With trackTracing off
     * the header is still sent (the trace id links errors across services) but no span is kept.
     *
     * @template T
     * @param callable(array<string, string>): T $send
     * @param array<string, mixed> $data
     * @return T
     */
    public static function httpSpan(string $method, string $url, callable $send, array $data = []): mixed
    {
        $request = self::$request;
        if ($request === null) {
            return $send([]);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : null;
        $spanId = TraceParent::generateSpanId();
        $headers = self::configuration()->shouldPropagateTrace($host)
            ? [TraceParent::HEADER => TraceParent::build($request->traceId, $spanId)]
            : [];

        $trace = self::$trace;
        if ($trace === null) {
            return $send($headers);
        }

        $trace->open($spanId);
        $startedAt = microtime(true);
        try {
            return $send($headers);
        } finally {
            $name = strtoupper($method) . ' ' . ($host ?? 'unknown');
            $trace->finish($spanId, $name, 'http', $startedAt, (microtime(true) - $startedAt) * 1000, $data);
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

    /**
     * @internal not part of the public API: resets static state between test cases. The startup
     * change snapshot is left marked as already scheduled, so the many tests that init() an enabled
     * client don't each schedule a real one for the end of the test run; pass changeSnapshot: true
     * to let the next init() schedule it (see ChangeSnapshotTest).
     */
    public static function resetForTesting(bool $changeSnapshot = false): void
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
        self::$changeQueue = null;
        self::$pendingChangeSnapshot = null;
        self::$changeSnapshotScheduled = !$changeSnapshot;
        self::$trace = null;
        self::$request = null;
        self::$snapshots = null;
        self::$exceptionHandlerInstalled = false;
        self::$previousExceptionHandler = null;
        self::$currentUser = null;
        self::$breadcrumbs = null;
    }
}
