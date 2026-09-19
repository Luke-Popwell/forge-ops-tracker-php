<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerSessionListener;
use ForgeOps\Tracker\SessionFlusher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Dispatches the three kernel events directly through a real EventDispatcher, the same technique
 * SymfonyIntegrationTest uses for kernel.exception: wiring is what's specific to this
 * integration, not real HTTP delivery, which SessionFlusherTest already covers.
 */
final class SymfonySessionIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testRecordsACrashFreeSessionForASuccessfulResponse(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithSessionListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('ok', 200)),
            KernelEvents::RESPONSE
        );

        self::assertSame([false], $recorded);
    }

    public function testRecordsACrashedSessionForAFiveHundredResponseWithNoException(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithSessionListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('error', 503)),
            KernelEvents::RESPONSE
        );

        self::assertSame([true], $recorded);
    }

    public function testRecordsACrashedSessionWhenAnExceptionWasCaughtEarlier(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcherWithSessionListener($recorded);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), KernelEvents::REQUEST);
        $dispatcher->dispatch(
            new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')),
            KernelEvents::EXCEPTION
        );
        // Symfony's own exception handling always produces a response for the request to finish
        // with, dispatched as kernel.response even after an exception; here it happens to be a
        // plain 200 error page, so only the attribute set by onKernelException should count.
        $dispatcher->dispatch(
            new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('error page', 200)),
            KernelEvents::RESPONSE
        );

        self::assertSame([true], $recorded);
    }

    /** @param array<int, bool> $recorded */
    private function dispatcherWithSessionListener(array &$recorded): EventDispatcher
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');

        $flusher = $this->createMock(SessionFlusher::class);
        $flusher->method('recordSession')->willReturnCallback(function (bool $crashed) use (&$recorded): void {
            $recorded[] = $crashed;
        });

        // No setAccessible(true): deprecated as of PHP 8.5, no effect since PHP 8.1 (verified
        // directly, not assumed).
        $property = new ReflectionProperty(ForgeOpsTracker::class, 'sessionFlusher');
        $property->setValue(null, $flusher);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ForgeOpsTrackerSessionListener());

        return $dispatcher;
    }
}
