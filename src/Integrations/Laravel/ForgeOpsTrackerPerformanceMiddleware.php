<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use Closure;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\QueryNaming;
use ForgeOps\Tracker\QueryPlanner;
use ForgeOps\Tracker\QueryPlans;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Throwable;
use WeakMap;

/**
 * Register globally, the same way as ForgeOpsTrackerSessionMiddleware (see that class's own doc
 * comment for the exact registration snippet), so every request gets timed:
 *
 *     $middleware->prepend(ForgeOpsTrackerPerformanceMiddleware::class);
 *
 * Times $next($request) directly: Laravel's own HTTP kernel wraps the whole middleware pipeline
 * in one try/catch the same way ForgeOpsTrackerSessionMiddleware's own doc comment documents, so
 * a plain wall-clock diff around that one call covers the request whether it succeeds, is caught
 * and converted to a response, or actually throws back out through here.
 *
 * transactionName is "<HTTP method> <route URI>", e.g. "GET users/{id}", not the raw path:
 * $request->route()?->uri() is Laravel's own matched route pattern, which keeps a distinct user
 * id from exploding into its own separate transaction the way the literal path would. Falls back
 * to $request->path() if no route matched at all (a 404).
 *
 * Also times every database query the request makes along the way, via DB::listen(), registered
 * fresh at the top of every single handle() call rather than once in a service provider's boot():
 * PHP-FPM re-bootstraps the whole app on every request anyway, so there's no meaningful
 * "registered once, forever" step to reach for here, unlike Rails' own global
 * sql.active_record subscription. Illuminate\Database\Events\QueryExecuted already carries
 * $query->time as milliseconds, pre-computed by Laravel itself; no manual timing needed the way
 * the request itself above does.
 *
 * The DB::listen() call itself is wrapped in its own try/catch, not just an app()->bound('db')
 * check: 'db' is a core framework service, registered by Laravel's own DatabaseServiceProvider
 * whether or not the app actually has a working database connection configured, so bound('db')
 * alone is true in almost every real app regardless. DB::listen() immediately resolves the
 * default connection to attach to, which throws when that connection is missing or misconfigured
 * (confirmed directly against the installed laravel/framework source: DatabaseManager has no
 * listen() of its own, so the call forwards through __call into connection()->listen(), and
 * connection() eagerly builds that connection's config). An app with no real database at all (a
 * pure API gateway, say) must never have this new addition break request/controller timing, which
 * never depended on a database existing in the first place; this also keeps the middleware usable
 * standalone, against a fake $next and no real Laravel container at all, the same way this SDK's
 * own tests already exercise it (see LaravelPerformanceIntegrationTest's own doc comment).
 *
 * Also opens a trace for the request (finished in the same finally as the performance sample, root
 * span named like the transaction) and records every query above as a "database" span under it, so a
 * slow request's own breakdown reaches /spans. Outbound HTTP made through Laravel's Http client
 * is not instrumented automatically (no stable global hook across supported versions): wrap it
 * in ForgeOpsTracker::httpSpan(), which also hands it the traceparent header to send.
 *
 * Each database span carries "db.statement" (the SQL with every string and number masked to "?")
 * and "db.system" (from the connection's driver). With explainSlowQueries on, a slow plain SELECT
 * on a PostgreSQL connection is also queued for an EXPLAIN, which terminate() runs after the
 * response has been sent (see QueryPlanner and explainOnNewConnection()).
 *
 * The trace continues the caller's when the request arrived with a usable W3C `traceparent`
 * header (see TraceParent), and its trace id exists even with trackTracing off, since every error
 * reported during the request carries it. The request is named (transaction name and endpoint) the
 * moment Laravel's router fires RouteMatched, before any route middleware or the controller runs,
 * so an error reported from inside the controller already carries both; global middleware like
 * this one runs before routing, which is why it can't simply read $request->route() up front.
 *
 * An exception that escapes $next() (Laravel normally converts one to a response further in, but
 * not every setup does) gets the request's context copied onto it before being rethrown unchanged
 * (see ForgeOpsTracker::snapshotOnto()): the kernel reports such an exception only after this
 * finally, and the user/breadcrumb middleware's own, have already cleared their state.
 *
 * Also records a breadcrumb alongside each of the two performance samples above (query and
 * controller), gated on trackBreadcrumbs independently of trackPerformance: see
 * ForgeOpsTracker::recordBreadcrumb()'s own doc comment for where that flag check actually lives.
 * An app could want the trail without the timing data, or vice versa, and each already has to be
 * recorded from this exact call site regardless, so there's no real cost to keeping them
 * independent rather than tying breadcrumbs to whether performance monitoring happens to be on,
 * the same reasoning gems/forge_ops_tracker's own railtie.rb documents for its identical two
 * sources.
 */
final class ForgeOpsTrackerPerformanceMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            DB::listen(static function (QueryExecuted $query): void {
                if (QueryPlanner::isExplaining()) {
                    return; // the EXPLAIN's own statements, never an app query
                }
                $transactionName = QueryNaming::transactionName($query->sql);
                $durationMs = $query->time ?? 0.0;
                ForgeOpsTracker::recordPerformance($transactionName, $durationMs, 'query');
                ForgeOpsTracker::recordBreadcrumb('query', $transactionName, data: ['duration_ms' => round($durationMs, 1)]);
                // $query->time is milliseconds; the listener fires right after the query finished.
                // The span carries the masked statement and db.system; the raw SQL and bindings are
                // only captured by the explain closure, held until terminate() runs it.
                $dbSystem = QueryPlans::dbSystem(self::driverName($query));
                $explain = null;
                if ($dbSystem === 'postgresql') {
                    $connection = $query->connection;
                    $sql = $query->sql;
                    $bindings = $query->bindings;
                    $explain = static fn (): mixed => self::explainOnNewConnection($connection, $sql, $bindings);
                }
                ForgeOpsTracker::recordDatabaseQuery($transactionName, microtime(true) - $durationMs / 1000, $durationMs, $query->sql, $dbSystem, $explain);
            });
        } catch (Throwable $e) {
            ForgeOpsTracker::configuration()->log(
                '[forge-ops-tracker] could not attach query listener: ' . get_class($e) . ': ' . $e->getMessage()
            );
        }

        $start = microtime(true);
        $response = null;
        ForgeOpsTracker::startTrace($request->headers->get('traceparent'));
        self::listenForRouteMatched();
        // Already routed only when exercised standalone with a route resolver set (see
        // LaravelPerformanceIntegrationTest); in a real app this is null until RouteMatched.
        $route = $request->route();
        if (is_object($route) && method_exists($route, 'uri')) {
            ForgeOpsTracker::setRequestRoute(...self::names($request, $route->uri()));
        }

        try {
            $response = $next($request);

            return $response;
        } catch (Throwable $e) {
            ForgeOpsTracker::snapshotOnto($e);

            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            $route = $request->route()?->uri() ?? $request->path();
            $transactionName = $request->method() . ' ' . $route;
            ForgeOpsTracker::recordPerformance($transactionName, $durationMs, 'controller');

            // Duck-typed the same way ForgeOpsTrackerSessionMiddleware checks a response's status
            // code: $response is null if $next() threw (the finally still runs), and need not be
            // an Illuminate\Http\Response at all when this middleware is exercised standalone
            // against a fake $next (see this class's own doc comment).
            $status = is_object($response) && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;
            ForgeOpsTracker::recordBreadcrumb(
                'controller',
                $transactionName,
                $status !== null && $status >= 500 ? 'error' : 'info',
                array_filter(['status' => $status, 'path' => $request->path()], static fn ($value) => $value !== null),
            );

            ForgeOpsTracker::finishTrace($transactionName, $start, $durationMs);
        }
    }

    /**
     * Laravel calls this on global middleware after the response has been sent: the point where
     * the opt-in EXPLAINs queued during the request run (see QueryPlanner). Never throws.
     */
    public function terminate(Request $request, mixed $response): void
    {
        ForgeOpsTracker::runQueryPlans();
    }

    /**
     * Runs after the response (from terminate()), never during the request. Skips entirely while
     * the app's own connection is still inside a transaction. Otherwise builds a brand new
     * connection from the same config through Laravel's connection factory (not registered with
     * the DatabaseManager and with no event dispatcher, so the app's connection, its PDO and its
     * transaction state are never touched), makes its transaction READ ONLY with a
     * statement_timeout, runs EXPLAIN (FORMAT JSON) with the original SQL and bindings, rolls back
     * and disconnects. Returns the JSON text Postgres returns.
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function explainOnNewConnection(object $appConnection, string $sql, array $bindings): mixed
    {
        if (method_exists($appConnection, 'transactionLevel') && $appConnection->transactionLevel() > 0) {
            throw new \RuntimeException('the app connection is inside a transaction');
        }

        $config = $appConnection->getConfig();
        $fresh = app('db.factory')->make(is_array($config) ? $config : [], $appConnection->getName() . '_forge_ops_explain');
        try {
            $fresh->beginTransaction();
            $fresh->statement('SET TRANSACTION READ ONLY');
            $fresh->statement("SET LOCAL statement_timeout = '" . QueryPlans::STATEMENT_TIMEOUT . "'");
            $rows = $fresh->select('EXPLAIN (FORMAT JSON) ' . $sql, $bindings);
        } finally {
            try {
                $fresh->rollBack();
            } catch (Throwable) {
                // Nothing to roll back if beginTransaction() itself failed.
            }
            $fresh->disconnect();
        }

        $row = $rows[0] ?? null;
        $values = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);

        return $values === [] ? null : reset($values);
    }

    private static function driverName(QueryExecuted $query): ?string
    {
        try {
            return $query->connection->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The transaction name ("GET users/{id}", same as the performance sample) and endpoint
     * ("GET /users/{id}": Laravel's route URI has no leading slash, the endpoint always does).
     *
     * @return array{0: string, 1: string}
     */
    public static function names(Request $request, string $uri): array
    {
        return [$request->method() . ' ' . $uri, $request->method() . ' /' . ltrim($uri, '/')];
    }

    /**
     * Registers the RouteMatched listener once per event dispatcher, not once per request: under
     * Octane the same dispatcher lives across many requests, and a listener added on every request
     * would pile up. Does nothing when there's no container or dispatcher at all (the middleware
     * exercised standalone), since naming the request then falls back to the finally above.
     */
    private static function listenForRouteMatched(): void
    {
        static $registered = null;
        $registered ??= new WeakMap();

        try {
            if (!function_exists('app') || !app()->bound('events')) {
                return;
            }
            $events = app('events');
            if (!$events instanceof Dispatcher || isset($registered[$events])) {
                return;
            }
            $registered[$events] = true;

            $events->listen(RouteMatched::class, static function (RouteMatched $event): void {
                ForgeOpsTracker::setRequestRoute(...self::names($event->request, $event->route->uri()));
            });
        } catch (Throwable $e) {
            ForgeOpsTracker::configuration()->log(
                '[forge-ops-tracker] could not attach route listener: ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }
}
