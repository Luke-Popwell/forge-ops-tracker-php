<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerPerformanceMiddleware;
use ForgeOps\Tracker\PerformanceFlusher;
use ForgeOps\Tracker\SpanFlusher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Exercises the middleware class directly against a fake $next closure, same technique
 * LaravelSessionIntegrationTest uses: wiring is what's specific to this integration, not real
 * HTTP delivery, which PerformanceFlusherTest already covers.
 */
final class LaravelPerformanceIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testRecordsTheRequestsOwnDuration(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $response = $middleware->handle(Request::create('/ok'), fn () => new Response('ok', 200));

        self::assertSame('ok', $response->getContent());
        self::assertCount(1, $recorded);
        self::assertGreaterThanOrEqual(0, $recorded[0][1]);
    }

    public function testAlsoRecordsAControllerBreadcrumbAlongsideThePerformanceSample(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);
        ForgeOpsTracker::startBreadcrumbTrail();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/ok'), fn () => new Response('ok', 200));

        $breadcrumbs = ForgeOpsTracker::currentBreadcrumbs();
        self::assertCount(1, $breadcrumbs);
        self::assertSame('controller', $breadcrumbs[0]['category']);
        self::assertSame('GET ok', $breadcrumbs[0]['message']);
        self::assertSame('info', $breadcrumbs[0]['level']);
        self::assertSame(200, $breadcrumbs[0]['data']['status']);
    }

    public function testRecordsTheControllerBreadcrumbAtErrorLevelForAFiveHundredResponse(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);
        ForgeOpsTracker::startBreadcrumbTrail();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/broken'), fn () => new Response('error', 503));

        self::assertSame('error', ForgeOpsTracker::currentBreadcrumbs()[0]['level']);
    }

    public function testDoesNotRecordAControllerBreadcrumbWhenTrackBreadcrumbsIsOff(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded, trackBreadcrumbs: false);
        ForgeOpsTracker::startBreadcrumbTrail();

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/ok'), fn () => new Response('ok', 200));

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testUsesTheMatchedRoutesOwnUriPatternNotTheRawPath(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        $request = Request::create('/users/42');
        // setRouteResolver, not a real booted router: the same fake-route-injection technique
        // Laravel's own test helpers use, since exercising the middleware directly (see this
        // class's own doc comment) never boots real routing.
        $request->setRouteResolver(fn () => new class () {
            public function uri(): string
            {
                return 'users/{id}';
            }
        });

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle($request, fn () => new Response('user 42', 200));

        // The route pattern, not "GET /users/42": a distinct user id must not explode into its
        // own separate transaction the way the literal path would.
        self::assertSame('GET users/{id}', $recorded[0][0]);
    }

    public function testFallsBackToTheRawPathWhenNoRouteMatched(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/nowhere'), fn () => new Response('not found', 404));

        self::assertSame('GET nowhere', $recorded[0][0]);
    }

    public function testASlowRequestReportsItsTraceWithTheRequestAsRootSpan(): void
    {
        $recorded = [];
        $this->injectPerformanceFlusher($recorded);
        ForgeOpsTracker::configuration()->traceCaptureThreshold = 0.01;
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$delivered): bool {
            $delivered[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))
            ->setValue(null, new SpanFlusher(ForgeOpsTracker::configuration(), $client));

        $middleware = new ForgeOpsTrackerPerformanceMiddleware();
        $middleware->handle(Request::create('/slow'), function () {
            ForgeOpsTracker::span('inner work', static function (): void {
                usleep(30000);
            });

            return new Response('ok', 200);
        });
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $delivered);
        self::assertSame(['GET slow', 'inner work'], array_column($delivered[0]['spans'], 'name'));
    }

    /** @param array<int, array{0: string, 1: float}> $recorded */
    private function injectPerformanceFlusher(array &$recorded, ?bool $trackBreadcrumbs = null): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            // enabledEnvironments/environment set explicitly so isEnabled() is true: unlike
            // recordPerformance() (mocked below, so its own isEnabled() check inside the real
            // PerformanceFlusher never runs), recordBreadcrumb() checks isEnabled() itself,
            // directly, with nothing standing in for it here to bypass that check.
            enabledEnvironments: ['production'],
            environment: 'production',
            trackBreadcrumbs: $trackBreadcrumbs,
        );

        $flusher = $this->createMock(PerformanceFlusher::class);
        $flusher->method('record')->willReturnCallback(function (string $transactionName, float $durationMs) use (&$recorded): void {
            $recorded[] = [$transactionName, $durationMs];
        });

        $property = new ReflectionProperty(ForgeOpsTracker::class, 'performanceFlusher');
        $property->setValue(null, $flusher);
    }
}
