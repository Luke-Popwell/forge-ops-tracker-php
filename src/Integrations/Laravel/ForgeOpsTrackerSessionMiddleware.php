<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use Closure;
use ForgeOps\Tracker\ForgeOpsTracker;
use Illuminate\Http\Request;
use Throwable;

/**
 * Register globally, not on a specific route, so every request counts toward the crash-free rate:
 *
 *     // bootstrap/app.php
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->prepend(ForgeOpsTrackerSessionMiddleware::class);
 *     })
 *
 * (Laravel 10 and earlier: add it first in Http\Kernel::$middleware instead.) Prepended so it
 * wraps as much of the rest of the pipeline as possible, mirroring the same "register early,
 * before anything that would swallow an exception first" advice ForgeOpsTracker's own middleware
 * already gives elsewhere.
 *
 * Laravel's HTTP kernel wraps the entire middleware pipeline in one try/catch, converting an
 * exception to a response only after it has already propagated all the way back out to the
 * kernel; a plain try/catch/rethrow here genuinely observes the real exception the same way a
 * Rack middleware or an ASP.NET Core middleware does. A status-code check is kept as well, for a
 * response some other middleware or exception renderer turned into a 5xx without ever throwing
 * back through here.
 */
final class ForgeOpsTrackerSessionMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $crashed = false;

        try {
            $response = $next($request);
            $crashed = method_exists($response, 'getStatusCode') && $response->getStatusCode() >= 500;

            return $response;
        } catch (Throwable $e) {
            $crashed = true;
            throw $e;
        } finally {
            ForgeOpsTracker::recordSession($crashed);
        }
    }
}
