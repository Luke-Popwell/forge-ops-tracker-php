<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerBreadcrumbMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the middleware class directly against a fake $next closure, the same technique
 * LaravelSessionIntegrationTest/LaravelPerformanceIntegrationTest use.
 */
final class LaravelBreadcrumbIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testGivesTheRequestAFreshTrailForTheDurationOfTheCallThenClearsIt(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');

        $seenDuringCall = null;
        $middleware = new ForgeOpsTrackerBreadcrumbMiddleware();
        $response = $middleware->handle(Request::create('/'), function () use (&$seenDuringCall) {
            ForgeOpsTracker::addBreadcrumb('during the request');
            $seenDuringCall = ForgeOpsTracker::currentBreadcrumbs();

            return new Response('ok', 200);
        });

        self::assertSame('ok', $response->getContent());
        self::assertCount(1, $seenDuringCall);
        self::assertSame('during the request', $seenDuringCall[0]['message']);
        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testGivesEachCallItsOwnTrailNotOneSharedAcrossRequestsOnTheSameLongRunningWorker(): void
    {
        // The Laravel Octane/Swoole/RoadRunner case this middleware is specifically built for
        // (see its own doc comment): the same middleware instance handling many requests in one
        // long-running process must never let one request's trail bleed into the next.
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        $middleware = new ForgeOpsTrackerBreadcrumbMiddleware();

        $middleware->handle(Request::create('/'), function () {
            ForgeOpsTracker::addBreadcrumb('first request');

            return new Response('ok', 200);
        });

        $secondRequestTrail = 'not set at all';
        $middleware->handle(Request::create('/'), function () use (&$secondRequestTrail) {
            $secondRequestTrail = ForgeOpsTracker::currentBreadcrumbs();

            return new Response('ok', 200);
        });

        self::assertSame([], $secondRequestTrail);
    }

    public function testClearsTheTrailEvenWhenTheAppRaises(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        $middleware = new ForgeOpsTrackerBreadcrumbMiddleware();

        try {
            $middleware->handle(Request::create('/'), function (): void {
                ForgeOpsTracker::addBreadcrumb('before the crash');

                throw new RuntimeException('boom');
            });
            self::fail('expected the middleware to let the exception propagate');
        } catch (RuntimeException) {
            // expected: the middleware only observes, see its own doc comment
        }

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testDoesNotSetUpATrailAtAllWhenTrackBreadcrumbsIsOff(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events', trackBreadcrumbs: false);
        $middleware = new ForgeOpsTrackerBreadcrumbMiddleware();

        $seenDuringCall = 'not set at all';
        $middleware->handle(Request::create('/'), function () use (&$seenDuringCall) {
            $seenDuringCall = ForgeOpsTracker::currentBreadcrumbs();

            return new Response('ok', 200);
        });

        self::assertSame([], $seenDuringCall);
    }

    public function testDoesNotSetUpATrailWhenTheClientItselfIsNotEnabled(): void
    {
        ForgeOpsTracker::init(); // no dsn at all: isEnabled() is false
        $middleware = new ForgeOpsTrackerBreadcrumbMiddleware();

        $middleware->handle(Request::create('/'), fn () => new Response('ok', 200));

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }
}
