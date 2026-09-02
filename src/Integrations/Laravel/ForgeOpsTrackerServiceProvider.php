<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use ForgeOps\Tracker\ForgeOpsTracker;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Register in config/app.php's providers array:
 *
 *     'providers' => [
 *         ...,
 *         ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerServiceProvider::class,
 *     ],
 */
final class ForgeOpsTrackerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (!method_exists($handler, 'reportable')) {
            return;
        }

        // Reports, then lets Laravel's own reporting continue exactly as
        // if this provider weren't registered -- a reportable() callback
        // only stops the normal report() flow if it explicitly returns
        // false, which this never does. Only fires for an exception that
        // actually reaches the exception handler; anything your own code
        // already catches never reaches here at all.
        $handler->reportable(function (Throwable $e): void {
            ForgeOpsTracker::captureException($e, [
                'path' => request()?->path(),
                'method' => request()?->method(),
            ]);
        });
    }
}
