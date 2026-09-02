<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Symfony\ForgeOpsTrackerExceptionListener;
use ForgeOps\Tracker\Reporter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wiring is verified against a substituted mock Reporter (via Reflection
 * into ForgeOpsTracker's private static property), not real HTTP delivery
 * -- Client/DeliveryQueue/Reporter's own actual delivery behavior is
 * already covered elsewhere; what's specific to this integration is
 * whether the Symfony event wiring itself is correct.
 */
final class SymfonyIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testReportsTheExceptionAndLeavesTheEventUntouched(): void
    {
        $reportedThrowable = null;
        $reportedContext = null;

        $reporter = $this->createMock(Reporter::class);
        $reporter->expects(self::once())->method('report')->willReturnCallback(
            function ($throwable, $context) use (&$reportedThrowable, &$reportedContext): void {
                $reportedThrowable = $throwable;
                $reportedContext = $context;
            }
        );
        $this->injectReporter($reporter);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ForgeOpsTrackerExceptionListener());

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/throw', 'GET');
        $throwable = new RuntimeException('boom');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);

        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);

        self::assertSame($throwable, $reportedThrowable);
        self::assertSame('/throw', $reportedContext['path']);
        self::assertSame('GET', $reportedContext['method']);
        // The listener never calls setThrowable()/setResponse() -- confirms
        // it only observes; Symfony's own exception handling behaves
        // exactly as if this listener weren't registered.
        self::assertSame($throwable, $event->getThrowable());
        self::assertFalse($event->hasResponse());
    }

    private function injectReporter(Reporter $reporter): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events');
        // No setAccessible(true) -- deprecated as of PHP 8.5, no effect
        // since PHP 8.1 (verified directly, not assumed).
        $property = new ReflectionProperty(ForgeOpsTracker::class, 'reporter');
        $property->setValue(null, $reporter);
    }
}
