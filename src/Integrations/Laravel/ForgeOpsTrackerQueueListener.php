<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Laravel;

use ForgeOps\Tracker\ForgeOpsTracker;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;

/**
 * Register once, e.g. in a service provider's boot():
 *
 *     ForgeOpsTrackerQueueListener::register();
 *
 * Unlike the HTTP middleware (registered per-request, since a fresh PHP-FPM process boots the
 * whole app on every request anyway), a queue worker process is long-running and never touches
 * the HTTP middleware stack at all, so this is its own separate, explicit opt-in: the same
 * "explicit registration, no magic auto-discovery" convention every Laravel integration in this
 * SDK already follows.
 *
 * Queue::before/Queue::after/Queue::failing are real Illuminate\Queue\QueueManager methods
 * (confirmed directly against the installed laravel/framework source, not assumed) listening for
 * JobProcessing/JobProcessed/JobFailed respectively. $job->resolveName() (Illuminate\Contracts\Queue\Job)
 * is Laravel's own resolved display name for the job class, the exact method this SDK's own
 * existing Queue::failing() doc snippet already uses for error reporting; this integration is
 * that same wiring, packaged, plus performance timing alongside it.
 *
 * Also gives every job attempt its own fresh breadcrumb trail, the identical "own concern, own
 * middleware/listener" reset ForgeOpsTrackerBreadcrumbMiddleware gives every HTTP request: a queue
 * worker process (`php artisan queue:work`) is long-running by design, looping over many jobs in
 * one PHP process that never restarts between them, so relying on PHP-FPM's own per-request reset
 * isn't just weaker here than under Laravel Octane, it's completely absent; this reset is not an
 * edge case for queue jobs the way it is for plain PHP-FPM requests, it is the only thing that
 * makes per-job isolation work at all.
 *
 * Records one breadcrumb of its own too: "started job X", recorded in Queue::before (job start),
 * not Queue::after (job end). This mirrors sdks/python's own Celery integration, which made the
 * identical call for the identical reason (see sdks/PROGRESS.md's Python breadcrumbs entry):
 * Queue::failing fires instead of Queue::after for a job that ultimately fails, so a breadcrumb
 * recorded only at completion would never make it into that same job's own failure report, the
 * one report a breadcrumb trail would actually be useful on.
 */
final class ForgeOpsTrackerQueueListener
{
    /** @var array<int, float> spl_object_id($job) => start time (microtime(true)) */
    private static array $startTimes = [];

    public static function register(): void
    {
        Queue::before(static function (JobProcessing $event): void {
            self::$startTimes[spl_object_id($event->job)] = microtime(true);
            ForgeOpsTracker::startBreadcrumbTrail();
            ForgeOpsTracker::recordBreadcrumb('job', $event->job->resolveName());
        });

        Queue::after(static function (JobProcessed $event): void {
            self::recordDuration($event->job);
            // A worker process's own eventual shutdown is not a meaningful per-job boundary the
            // way one HTTP request's is (see PerformanceFlusher::flush()'s own comment): flush
            // explicitly after every job rather than letting it accumulate for however long this
            // worker process happens to keep running.
            ForgeOpsTracker::flushPerformance();
            ForgeOpsTracker::endBreadcrumbTrail();
        });

        // A failed attempt with retries remaining fires this (not Queue::failing, which only
        // fires once retries are exhausted) but never Queue::after, since the job didn't
        // complete: without this, that attempt's own start time would sit in $startTimes forever,
        // a slow leak for a worker that runs a very long time. Cleans up only; does not report a
        // performance sample for a failed attempt, matching this integration's own scope (see
        // Queue::failing below for what does get reported on a genuine failure). Leaves the
        // breadcrumb trail alone too: the next retry's own Queue::before call replaces it with a
        // fresh one anyway, and if there is no next retry (this was the last attempt), Queue::failing
        // below needs this same trail still intact to attach to the failure it reports.
        Queue::exceptionOccurred(static function (JobExceptionOccurred $event): void {
            unset(self::$startTimes[spl_object_id($event->job)]);
        });

        Queue::failing(static function (JobFailed $event): void {
            ForgeOpsTracker::captureException($event->exception, ['job' => $event->job->resolveName()]);
            ForgeOpsTracker::endBreadcrumbTrail();
        });
    }

    private static function recordDuration(mixed $job): void
    {
        $id = spl_object_id($job);
        $start = self::$startTimes[$id] ?? null;
        unset(self::$startTimes[$id]);

        if ($start === null) {
            return;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        ForgeOpsTracker::recordPerformance($job->resolveName(), $durationMs, 'job');
    }

    /** @internal not part of the public API: resets static state between test cases */
    public static function resetForTesting(): void
    {
        self::$startTimes = [];
    }
}
