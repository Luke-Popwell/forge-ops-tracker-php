<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations\Laravel;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerQueueListener;
use ForgeOps\Tracker\PerformanceFlusher;
use ForgeOps\Tracker\Reporter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Registers the listener against a real Testbench app (Queue::before/after/failing forward
 * straight into the app's own event dispatcher, confirmed directly against the installed
 * laravel/framework source, so no real queue connection needs to be configured); dispatches the
 * same events a real worker would, directly, rather than actually running a job through a queue
 * connection, since ForgeOpsTrackerQueueListener only ever reacts to these events regardless of
 * how they were raised.
 */
final class ForgeOpsTrackerQueueListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTrackerQueueListener::resetForTesting();
        ForgeOpsTracker::resetForTesting();
        parent::tearDown();
    }

    public function testRecordsAJobsDurationAsKindJobAndFlushesImmediately(): void
    {
        $recorded = [];
        $flusher = $this->injectPerformanceFlusher($recorded);
        $flusher->expects(self::once())->method('flush');

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\SendWelcomeEmail');
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));

        self::assertCount(1, $recorded);
        self::assertSame('App\Jobs\SendWelcomeEmail', $recorded[0][0]);
        self::assertSame('job', $recorded[0][2]);
        self::assertGreaterThanOrEqual(0, $recorded[0][1]);
    }

    public function testGivesEachJobAttemptAFreshBreadcrumbTrailAndRecordsAStartedJobBreadcrumb(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);
        ForgeOpsTracker::addBreadcrumb('leftover from a previous, unrelated job');

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\SendWelcomeEmail');
        event(new JobProcessing('sync', $job));

        $breadcrumbs = ForgeOpsTracker::currentBreadcrumbs();
        self::assertCount(1, $breadcrumbs);
        self::assertSame('job', $breadcrumbs[0]['category']);
        self::assertSame('App\Jobs\SendWelcomeEmail', $breadcrumbs[0]['message']);
    }

    public function testClearsTheBreadcrumbTrailOnceTheJobCompletesSuccessfully(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\SendWelcomeEmail');
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testTwoSequentialJobsOnTheSameLongRunningWorkerNeverShareATrail(): void
    {
        // A queue worker process (php artisan queue:work) is long-running by design: this is not
        // an Octane-only edge case the way it is for HTTP requests, it is the only thing that
        // makes per-job breadcrumb isolation work at all (see ForgeOpsTrackerQueueListener's own
        // doc comment).
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        ForgeOpsTrackerQueueListener::register();

        $firstJob = $this->job('App\Jobs\SendWelcomeEmail');
        event(new JobProcessing('sync', $firstJob));
        ForgeOpsTracker::addBreadcrumb('from the first job');
        event(new JobProcessed('sync', $firstJob));

        $secondJob = $this->job('App\Jobs\ChargeCard');
        event(new JobProcessing('sync', $secondJob));

        $secondJobMessages = array_column(ForgeOpsTracker::currentBreadcrumbs(), 'message');
        self::assertNotContains('from the first job', $secondJobMessages);
    }

    public function testTheFailingJobsOwnBreadcrumbTrailIsStillAttachedToItsFailureReport(): void
    {
        $reported = [];
        $reporter = $this->createMock(Reporter::class);
        $reporter->method('report')->willReturnCallback(
            function ($throwable, array $context = [], ?array $user = null, array $breadcrumbs = []) use (&$reported): void {
                $reported[] = $breadcrumbs;
            }
        );
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            enabledEnvironments: ['production'],
            environment: 'production',
        );
        (new ReflectionProperty(ForgeOpsTracker::class, 'reporter'))->setValue(null, $reporter);

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\ChargeCard');
        event(new JobProcessing('sync', $job));
        ForgeOpsTracker::addBreadcrumb('about to charge the card');
        event(new JobFailed('sync', $job, new RuntimeException('card declined')));

        self::assertCount(1, $reported);
        // The "started job" breadcrumb from Queue::before, plus the manual one added during the
        // job, both still present: nothing cleared this trail out from under Queue::failing's own
        // captureException() call, unlike the success path (JobProcessed), which does clear it.
        self::assertSame(
            ['App\Jobs\ChargeCard', 'about to charge the card'],
            array_column($reported[0], 'message')
        );
        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testCleansUpTheStartTimeOnExceptionOccurredSoARetriedAttemptNeverLeaksItForever(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\ChargeCard');
        event(new JobProcessing('sync', $job));
        event(new JobExceptionOccurred('sync', $job, new RuntimeException('card declined')));
        // No further JobProcessing for this same attempt (a real worker would not fire
        // JobProcessed for an attempt that already raised JobExceptionOccurred), but dispatching
        // it anyway here is exactly how a leaked start time would surface: recordDuration()
        // would find nothing to unset and (per its own null guard) still record nothing, proving
        // the cleanup, not just asserting the internal map is empty via reflection.
        event(new JobProcessed('sync', $job));

        self::assertSame([], $recorded);
    }

    public function testReportsTheExceptionWithTheJobNameAsContextOnAGenuineFailure(): void
    {
        $reported = [];
        $reporter = $this->createMock(Reporter::class);
        $reporter->method('report')->willReturnCallback(function ($throwable, array $context = []) use (&$reported): void {
            $reported[] = [$throwable, $context];
        });
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        (new ReflectionProperty(ForgeOpsTracker::class, 'reporter'))->setValue(null, $reporter);

        ForgeOpsTrackerQueueListener::register();

        $job = $this->job('App\Jobs\ChargeCard');
        $exception = new RuntimeException('card declined');
        event(new JobFailed('sync', $job, $exception));

        self::assertCount(1, $reported);
        [$reportedThrowable, $reportedContext] = $reported[0];
        self::assertSame($exception, $reportedThrowable);
        self::assertSame(['job' => 'App\Jobs\ChargeCard'], $reportedContext);
    }

    private function job(string $resolvedName): Job&\PHPUnit\Framework\MockObject\MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn($resolvedName);

        return $job;
    }

    /**
     * @param array<int, array{0: string, 1: float, 2: string}> $recorded
     * @return PerformanceFlusher&\PHPUnit\Framework\MockObject\MockObject
     */
    private function injectPerformanceFlusher(array &$recorded): PerformanceFlusher
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            // enabledEnvironments/environment set explicitly so isEnabled() is true:
            // recordBreadcrumb() (the "started job" breadcrumb) checks isEnabled() itself,
            // directly, with no mock standing in for it the way the mocked PerformanceFlusher
            // below bypasses that same check.
            enabledEnvironments: ['production'],
            environment: 'production',
        );

        $flusher = $this->createMock(PerformanceFlusher::class);
        $flusher->method('record')->willReturnCallback(function (string $transactionName, float $durationMs, string $kind = 'controller') use (&$recorded): void {
            $recorded[] = [$transactionName, $durationMs, $kind];
        });

        $property = new ReflectionProperty(ForgeOpsTracker::class, 'performanceFlusher');
        $property->setValue(null, $flusher);

        return $flusher;
    }
}
