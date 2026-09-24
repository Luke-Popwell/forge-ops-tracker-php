<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerExceptionListener;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerPerformanceListener;
use ForgeOps\Tracker\Tests\CapturesPayloads;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Dispatches the kernel events in the order HttpKernel does for a request whose controller throws
 * (request, exception, response) through a real EventDispatcher, same technique
 * SymfonyPerformanceIntegrationTest uses.
 */
final class SymfonyTraceContextIntegrationTest extends TestCase
{
    use CapturesPayloads;

    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PARENT_ID = '00f067aa0ba902b7';

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testAnUnhandledErrorCarriesTheContinuedTraceRouteNameAndPath(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        $routes = new RouteCollection();
        $routes->add('order_show', new Route('/orders/{id}'));
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);

        $this->runRequestThatThrows($events, new ForgeOpsTrackerPerformanceListener($router), 'order_show');

        self::assertSame(self::TRACE_ID, $events[0]['trace_id']);
        self::assertSame('GET order_show', $events[0]['transaction_name']);
        self::assertSame('GET /orders/{id}', $events[0]['endpoint']);
        // Fast, but errored: sent, with its root under the caller's span.
        self::assertCount(1, $traces);
        self::assertSame(self::PARENT_ID, $traces[0]['spans'][0]['parent_span_id']);
        self::assertNull(ForgeOpsTracker::currentTraceId());
    }

    public function testLeavesTheEndpointOutWithoutARouter(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);

        $this->runRequestThatThrows($events, new ForgeOpsTrackerPerformanceListener(), 'order_show');

        self::assertSame('GET order_show', $events[0]['transaction_name']);
        self::assertArrayNotHasKey('endpoint', $events[0]);
    }

    public function testNeverLoadsTheRouteCollectionForARequestWithoutErrors(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ForgeOpsTrackerPerformanceListener($router));
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/orders/1');
        $request->attributes->set('_route', 'order_show');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok')), KernelEvents::RESPONSE);

        self::assertSame([], $events);
    }

    /** @param array<int, array<string, mixed>> $events */
    private function runRequestThatThrows(array &$events, ForgeOpsTrackerPerformanceListener $listener, string $routeName): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $dispatcher->addSubscriber(new ForgeOpsTrackerExceptionListener());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/orders/42');
        $request->headers->set('traceparent', '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01');
        // Set directly, as Symfony's RouterListener would before this listener runs.
        $request->attributes->set('_route', $routeName);

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')),
            KernelEvents::EXCEPTION,
        );
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('error', 500)),
            KernelEvents::RESPONSE,
        );
        ForgeOpsTracker::flushSpans();
    }
}
