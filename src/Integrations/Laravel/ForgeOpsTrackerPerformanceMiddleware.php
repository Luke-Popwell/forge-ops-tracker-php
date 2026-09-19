<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use Closure;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\QueryNaming;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * in ForgeOpsTracker::span().
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
                $transactionName = QueryNaming::transactionName($query->sql);
                $durationMs = $query->time ?? 0.0;
                ForgeOpsTracker::recordPerformance($transactionName, $durationMs, 'query');
                ForgeOpsTracker::recordBreadcrumb('query', $transactionName, data: ['duration_ms' => round($durationMs, 1)]);
                // $query->time is milliseconds; the listener fires right after the query finished.
                ForgeOpsTracker::recordSpan($transactionName, 'database', microtime(true) - $durationMs / 1000, $durationMs);
            });
        } catch (Throwable $e) {
            ForgeOpsTracker::configuration()->log(
                '[forge-ops-tracker] could not attach query listener: ' . get_class($e) . ': ' . $e->getMessage()
            );
        }

        $start = microtime(true);
        $response = null;
        ForgeOpsTracker::startTrace();

        try {
            $response = $next($request);

            return $response;
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
}
