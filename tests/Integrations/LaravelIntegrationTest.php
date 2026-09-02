<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerServiceProvider;
use ForgeOps\Tracker\Reporter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Orchestra\Testbench\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Wiring is verified against a substituted mock Reporter (same technique
 * as SymfonyIntegrationTest), injected *after* the app has booted (so the
 * service provider's own boot()-time registration of the reportable()
 * callback is unaffected) but *before* the exception handler's report()
 * is actually invoked -- ForgeOpsTracker::captureException() only
 * resolves the reporter lazily, at the point it's actually called, not
 * at registration time.
 */
final class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeOpsTrackerServiceProvider::class];
    }

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
        parent::tearDown();
    }

    public function testReportsTheExceptionThroughLaravelsReportableHook(): void
    {
        $reportedThrowable = null;

        $reporter = $this->createMock(Reporter::class);
        $reporter->expects(self::once())->method('report')->willReturnCallback(
            function ($throwable) use (&$reportedThrowable): void {
                $reportedThrowable = $throwable;
            }
        );

        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        // No setAccessible(true) -- deprecated as of PHP 8.5, no effect
        // since PHP 8.1 (verified directly, not assumed).
        $property = new ReflectionProperty(ForgeOpsTracker::class, 'reporter');
        $property->setValue(null, $reporter);

        $handler = $this->app->make(ExceptionHandler::class);
        $throwable = new RuntimeException('boom');

        // report() is the same method Laravel's own exception-handling
        // pipeline calls for a real unhandled exception -- invoking it
        // directly is the standard way to exercise a registered
        // reportable() callback in a test, without needing a full HTTP
        // request/response cycle.
        $handler->report($throwable);

        self::assertSame($throwable, $reportedThrowable);
    }
}
