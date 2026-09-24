<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\DeliveryQueue;
use ForgeOps\Tracker\EventBuilder;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\Reporter;
use ForgeOps\Tracker\SpanFlusher;
use ReflectionProperty;

/**
 * Initializes the client (enabled) with a real Reporter/EventBuilder and SpanFlusher whose
 * delivery is replaced by mocks that collect what would have gone over the wire, so a test can
 * assert on the actual error payload and trace, not on arguments handed to a mock.
 */
trait CapturesPayloads
{
    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $traces
     */
    private function captureEventsAndTraces(array &$events, array &$traces, bool $tracing = true, ?float $threshold = null): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            environment: 'production',
            enabledEnvironments: ['production'],
            trackTracing: $tracing,
            traceCaptureThreshold: $threshold,
            installExceptionHandler: false,
        );
        $configuration = ForgeOpsTracker::configuration();

        $queue = $this->createMock(DeliveryQueue::class);
        $queue->method('push')->willReturnCallback(function (array $payload) use (&$events): bool {
            $events[] = $payload;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'reporter'))
            ->setValue(null, new Reporter($configuration, new EventBuilder($configuration), $queue));

        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$traces): bool {
            $traces[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))
            ->setValue(null, new SpanFlusher($configuration, $client));
    }
}
