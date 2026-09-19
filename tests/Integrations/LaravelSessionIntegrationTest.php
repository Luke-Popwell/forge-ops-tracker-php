<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests\Integrations;

use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Integrations\Laravel\ForgeOpsTrackerSessionMiddleware;
use ForgeOps\Tracker\SessionFlusher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Exercises the middleware class directly against a fake $next closure, the same technique
 * ForgeOpsTrackerMiddlewareTests uses in the .NET port for the equivalent case: wiring is what's
 * specific to this integration, not real HTTP delivery, which SessionFlusherTest already covers.
 */
final class LaravelSessionIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testRecordsACrashFreeSessionForASuccessfulResponse(): void
    {
        $recorded = [];
        $this->injectSessionFlusher($recorded);

        $middleware = new ForgeOpsTrackerSessionMiddleware();
        $response = $middleware->handle(Request::create('/'), fn () => new Response('ok', 200));

        self::assertSame('ok', $response->getContent());
        self::assertSame([false], $recorded);
    }

    public function testRecordsACrashedSessionForAFiveHundredResponseWithNoThrownException(): void
    {
        $recorded = [];
        $this->injectSessionFlusher($recorded);

        $middleware = new ForgeOpsTrackerSessionMiddleware();
        $middleware->handle(Request::create('/'), fn () => new Response('error', 503));

        self::assertSame([true], $recorded);
    }

    public function testRecordsACrashedSessionAndStillLetsTheExceptionPropagate(): void
    {
        $recorded = [];
        $this->injectSessionFlusher($recorded);

        $middleware = new ForgeOpsTrackerSessionMiddleware();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $middleware->handle(Request::create('/'), function (): void {
                throw new RuntimeException('boom');
            });
        } finally {
            self::assertSame([true], $recorded);
        }
    }

    /** @param array<int, bool> $recorded */
    private function injectSessionFlusher(array &$recorded): void
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
    }
}
