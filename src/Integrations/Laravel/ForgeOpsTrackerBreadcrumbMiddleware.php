<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use Closure;
use ForgeOps\Tracker\ForgeOpsTracker;
use Illuminate\Http\Request;

/**
 * Register globally, the same way as ForgeOpsTrackerSessionMiddleware (see that class's own doc
 * comment for the exact registration snippet), so every request gets a trail to accumulate into:
 *
 *     $middleware->prepend(ForgeOpsTrackerBreadcrumbMiddleware::class);
 *
 * A sibling to ForgeOpsTrackerSessionMiddleware/ForgeOpsTrackerUserContextMiddleware, not folded
 * into either: distinct concern, same "own file, own class" shape every middleware in this SDK
 * already takes, matching gems/forge_ops_tracker's own
 * ForgeOpsTracker::Middleware::BreadcrumbContext.
 *
 * Skips setting up a buffer at all when trackBreadcrumbs is off (or the client isn't enabled), the
 * same short-circuit the Ruby middleware takes: cheap, and means a request through an app that
 * never turned breadcrumbs on pays nothing for this beyond one flag check. The manual
 * ForgeOpsTracker::addBreadcrumb() API still works even then, via its own lazy fallback; only the
 * automatic sources this middleware and ForgeOpsTrackerPerformanceMiddleware/
 * ForgeOpsTrackerQueueListener feed are gated by trackBreadcrumbs.
 *
 * The finally-clear is load-bearing, not optional, for the identical Laravel Octane/Swoole/
 * RoadRunner reason ForgeOpsTracker::endBreadcrumbTrail()'s own doc comment gives: those keep the
 * whole application, statics included, alive across many requests in one long-running worker
 * process, where an uncleared buffer would leak one request's breadcrumbs into a later, unrelated
 * request handled on the same worker. Confirmed this SDK already claims Octane support (see
 * ForgeOpsTrackerUserContextMiddleware's own doc comment and the README's "Identifying users"
 * section), so this gets the identical treatment, not the weaker "PHP-FPM resets it anyway"
 * argument that would be enough for plain PHP-FPM alone.
 */
final class ForgeOpsTrackerBreadcrumbMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $configuration = ForgeOpsTracker::configuration();
        if ($configuration->trackBreadcrumbs && $configuration->isEnabled()) {
            ForgeOpsTracker::startBreadcrumbTrail();
        }

        // The clear below is unconditional, not nested inside the flag check above: even when
        // this middleware itself never started a trail, a manual addBreadcrumb() call from
        // somewhere inside $next() would otherwise still lazily create one (its own documented
        // "works regardless of trackBreadcrumbs" contract) that nothing would ever clear, exactly
        // the kind of leak this middleware exists to prevent. Matches Ruby's own
        // ForgeOpsTracker::Middleware::BreadcrumbContext, where the `ensure` clause is scoped to
        // the whole method and so already runs on this same early-return-adjacent path.
        try {
            return $next($request);
        } finally {
            ForgeOpsTracker::endBreadcrumbTrail();
        }
    }
}
