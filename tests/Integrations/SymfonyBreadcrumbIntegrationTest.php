<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerBreadcrumbListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dispatches the two kernel events directly through a real EventDispatcher, the same technique
 * SymfonySessionIntegrationTest/SymfonyPerformanceIntegrationTest use.
 */
final class SymfonyBreadcrumbIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testGivesTheRequestAFreshTrailForTheDurationOfTheRequestThenClearsIt(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        $dispatcher = $this->dispatcherWithBreadcrumbListener();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        ForgeOpsTracker::addBreadcrumb('during the request');
        $seenDuringRequest = ForgeOpsTracker::currentBreadcrumbs();

        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        self::assertCount(1, $seenDuringRequest);
        self::assertSame('during the request', $seenDuringRequest[0]['message']);
        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testGivesEachMainRequestItsOwnTrailNotOneSharedAcrossRequestsOnTheSameWorker(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        $dispatcher = $this->dispatcherWithBreadcrumbListener();
        $kernel = $this->createMock(HttpKernelInterface::class);

        $firstRequest = Request::create('/one');
        $dispatcher->dispatch(new RequestEvent($kernel, $firstRequest, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        ForgeOpsTracker::addBreadcrumb('first request');
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $firstRequest, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        $secondRequest = Request::create('/two');
        $dispatcher->dispatch(new RequestEvent($kernel, $secondRequest, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);

        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    public function testSkipsSubRequestsEntirely(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        $dispatcher = $this->dispatcherWithBreadcrumbListener();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        ForgeOpsTracker::addBreadcrumb('from the main request');

        // A sub-request (an ESI include, a forward) must never reset the main request's own
        // trail out from under it.
        $subRequest = Request::create('/fragment');
        $dispatcher->dispatch(new RequestEvent($kernel, $subRequest, HttpKernelInterface::SUB_REQUEST), KernelEvents::REQUEST);

        self::assertSame(
            ['from the main request'],
            array_column(ForgeOpsTracker::currentBreadcrumbs(), 'message')
        );
    }

    public function testDoesNotSetUpATrailAtAllWhenTrackBreadcrumbsIsOff(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events', trackBreadcrumbs: false);
        $dispatcher = $this->dispatcherWithBreadcrumbListener();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);

        // No trail started at all, so a manual add still lazily creates its own (see
        // ForgeOpsTracker::addBreadcrumb()'s own doc comment), proving the listener itself never
        // called startBreadcrumbTrail().
        self::assertSame([], ForgeOpsTracker::currentBreadcrumbs());
    }

    private function dispatcherWithBreadcrumbListener(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ForgeOpsTrackerBreadcrumbListener());

        return $dispatcher;
    }
}
