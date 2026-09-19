<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\SpanFlusher;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerPerformanceListener;
use ForgeOps\Tracker\PerformanceFlusher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dispatches the two kernel events directly through a real EventDispatcher, same technique
 * SymfonySessionIntegrationTest uses: wiring is what's specific to this integration, not real
 * HTTP delivery, which PerformanceFlusherTest already covers.
 */
final class SymfonyPerformanceIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testRecordsTheRequestsOwnDuration(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/ok');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        self::assertCount(1, $recorded);
        self::assertGreaterThanOrEqual(0, $recorded[0][1]);
    }

    public function testASlowRequestReportsItsTraceWithTheRequestAsRootSpan(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        ForgeOpsTracker::configuration()->traceCaptureThreshold = 0.01;
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$delivered): bool {
            $delivered[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))
            ->setValue(null, new SpanFlusher(ForgeOpsTracker::configuration(), $client));
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/slow');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        ForgeOpsTracker::span('inner work', static function (): void {
            usleep(30000);
        });
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $delivered);
        self::assertSame(['GET /slow', 'inner work'], array_column($delivered[0]['spans'], 'name'));
    }

    public function testAlsoRecordsAControllerBreadcrumbAlongsideThePerformanceSample(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        ForgeOpsTracker::startBreadcrumbTrail();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/ok');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        $breadcrumbs = ForgeOpsTracker::currentBreadcrumbs();
        self::assertCount(1, $breadcrumbs);
        self::assertSame('controller', $breadcrumbs[0]['category']);
        self::assertSame('info', $breadcrumbs[0]['level']);
        self::assertSame(200, $breadcrumbs[0]['data']['status']);
    }

    public function testRecordsTheControllerBreadcrumbAtErrorLevelForAFiveHundredResponse(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        ForgeOpsTracker::startBreadcrumbTrail();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/broken');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('error', 503)),
            KernelEvents::RESPONSE
        );

        self::assertSame('error', ForgeOpsTracker::currentBreadcrumbs()[0]['level']);
    }

    public function testDoesNotRecordAControllerBreadcrumbWhenTrackBreadcrumbsIsOff(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded, trackBreadcrumbs: false);
        ForgeOpsTracker::startBreadcrumbTrail();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/ok');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testUsesTheRoutesOwnNameNotTheRawPath(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/users/42');
        // Set directly, the same attribute Symfony's own router would set once a route
        // actually matches: exercising the listener directly (see this class's own doc
        // comment) never boots a real router.
        $request->attributes->set('_route', 'user_detail');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('user 42', 200)),
            KernelEvents::RESPONSE
        );

        // The route name, not "GET /users/42": a distinct user id must not explode into its
        // own separate transaction the way the literal path would.
        self::assertSame('GET user_detail', $recorded[0][0]);
    }

    public function testFallsBackToThePathInfoWhenNoRouteMatched(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithPerformanceListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/nowhere');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('not found', 404)),
            KernelEvents::RESPONSE
        );

        self::assertSame('GET /nowhere', $recorded[0][0]);
    }

    /** @param array<int, array{0: string, 1: float}> $recorded */
    private function dispatcherWithPerformanceListener(array &$recorded, ?bool $trackBreadcrumbs = null): EventDispatcher
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            // enabledEnvironments/environment set explicitly so isEnabled() is true:
            // recordBreadcrumb() checks isEnabled() itself, directly, with no mock standing in
            // for it the way the mocked PerformanceFlusher below bypasses that same check.
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

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ForgeOpsTrackerPerformanceListener());

        return $dispatcher;
    }
}
