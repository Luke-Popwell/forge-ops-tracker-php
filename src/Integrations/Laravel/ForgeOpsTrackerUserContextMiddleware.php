<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use Closure;
use ForgeOps\Tracker\ForgeOpsTracker;
use Illuminate\Http\Request;

/**
 * Automatically identifies the affected user for every error reported during a request, when
 * $request->user() resolves to one. Register globally, the same way as
 * ForgeOpsTrackerSessionMiddleware (see that class's own doc comment for the exact registration
 * snippet):
 *
 *     $middleware->prepend(ForgeOpsTrackerUserContextMiddleware::class);
 *
 * $request->user() resolves against whatever guard Laravel's own auth system is configured with
 * (session, token, Sanctum, ...), lazily, on demand: unlike Rails/Devise, there's no separate
 * "did the auth middleware already run" ordering concern here, since nothing about calling
 * user() itself requires an Authenticate middleware to have run first (that middleware only
 * aborts/redirects an unauthenticated request to a protected route; it isn't what makes user()
 * resolve). A request with no authenticated user, or no auth guard configured at all, just gets
 * null back, the same "duck-type, don't require a specific shape" no-op every other framework
 * auto-detection in this repo falls back to.
 *
 * Duck-types rather than assuming a specific User model: getAuthIdentifier() for id (the one
 * thing Laravel's own Authenticatable contract actually guarantees, verified directly against
 * the installed laravel/framework source), and email/username (or name, Laravel's own default
 * scaffolding's field for it) as plain optional attributes, present only if the app's own User
 * model happens to have them: the same "duck-type, don't require a specific shape" approach
 * gems/forge_ops_tracker's own Warden integration takes for the identical problem.
 *
 * Clears the static current-user in a finally, not just because PHP-FPM already resets every
 * static property between requests (see ForgeOpsTracker.php's own comment on why that's normally
 * safe): Laravel Octane (Swoole/RoadRunner) keeps the whole application, statics included, alive
 * across many requests in one long-running worker process, where an uncleared value genuinely
 * would leak from one request into an unrelated later one on the same worker. Cheap regardless of
 * deployment model, so it's unconditional here rather than only mattering under Octane
 * specifically.
 */
final class ForgeOpsTrackerUserContextMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if ($user !== null) {
            ForgeOpsTracker::setUser(
                id: (string) $user->getAuthIdentifier(),
                email: isset($user->email) ? (string) $user->email : null,
                username: isset($user->username) ? (string) $user->username : (isset($user->name) ? (string) $user->name : null),
            );
        }

        try {
            return $next($request);
        } finally {
            ForgeOpsTracker::setUser();
        }
    }
}
