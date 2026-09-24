<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\RequestContext;
use ForgeOps\Tracker\TraceParent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TraceContextTest extends TestCase
{
    use CapturesPayloads;

    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PARENT_ID = '00f067aa0ba902b7';
    private const HEADER = '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01';

    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    public function testParsesAValidTraceparent(): void
    {
        self::assertSame(
            ['trace_id' => self::TRACE_ID, 'parent_span_id' => self::PARENT_ID],
            TraceParent::parse(self::HEADER),
        );
        self::assertSame(self::TRACE_ID, TraceParent::parse('  ' . self::HEADER . ' ')['trace_id']);
    }

    public function testAcceptsAFutureVersionWithExtraFields(): void
    {
        self::assertSame(self::TRACE_ID, TraceParent::parse('01-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01-extra')['trace_id']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidHeaders(): array
    {
        return [
            'null' => [null],
            'not a string' => [42],
            'blank' => [''],
            'garbage' => ['not-a-traceparent'],
            'uppercase hex' => ['00-' . strtoupper(self::TRACE_ID) . '-' . self::PARENT_ID . '-01'],
            'version ff' => ['ff-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01'],
            'all-zero trace id' => ['00-' . str_repeat('0', 32) . '-' . self::PARENT_ID . '-01'],
            'all-zero parent id' => ['00-' . self::TRACE_ID . '-' . str_repeat('0', 16) . '-01'],
            'short trace id' => ['00-' . substr(self::TRACE_ID, 1) . '-' . self::PARENT_ID . '-01'],
            'extra field on version 00' => [self::HEADER . '-extra'],
            'missing flags' => ['00-' . self::TRACE_ID . '-' . self::PARENT_ID],
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsInvalidTraceparents(mixed $value): void
    {
        self::assertNull(TraceParent::parse($value));
    }

    public function testBuildsAndGeneratesW3cIds(): void
    {
        self::assertSame('00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01', TraceParent::build(self::TRACE_ID, self::PARENT_ID));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', TraceParent::generateTraceId());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', TraceParent::generateSpanId());
        self::assertNotNull(TraceParent::parse(TraceParent::build(TraceParent::generateTraceId(), TraceParent::generateSpanId())));
    }

    public function testRequestContextContinuesAValidHeaderAndStartsFreshOtherwise(): void
    {
        $continued = RequestContext::fromTraceparent(self::HEADER);
        self::assertSame(self::TRACE_ID, $continued->traceId);
        self::assertSame(self::PARENT_ID, $continued->parentSpanId);

        $fresh = RequestContext::fromTraceparent('garbage');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $fresh->traceId);
        self::assertNull($fresh->parentSpanId);
    }

    public function testPropagatesToEveryHostByDefault(): void
    {
        $configuration = new Configuration();

        self::assertTrue($configuration->propagateTraces);
        self::assertNull($configuration->tracePropagationTargets);
        self::assertTrue($configuration->shouldPropagateTrace('anything.example'));
    }

    public function testPropagateTracesOffNeverPropagates(): void
    {
        $configuration = new Configuration();
        $configuration->propagateTraces = false;

        self::assertFalse($configuration->shouldPropagateTrace('api.example.com'));
    }

    public function testMatchesTargetsAsHostsOnADotBoundaryOrAsPatterns(): void
    {
        $configuration = new Configuration();
        $configuration->tracePropagationTargets = ['.Example.com', '/^10\.0\./', '/[invalid/'];

        self::assertTrue($configuration->shouldPropagateTrace('example.com'));
        self::assertTrue($configuration->shouldPropagateTrace('API.example.com'));
        self::assertFalse($configuration->shouldPropagateTrace('badexample.com'));
        self::assertFalse($configuration->shouldPropagateTrace('example.com.evil.test'));
        self::assertTrue($configuration->shouldPropagateTrace('10.0.3.4'));
        self::assertFalse($configuration->shouldPropagateTrace('110.0.3.4'));
        self::assertFalse($configuration->shouldPropagateTrace(null));
    }

    public function testAnEmptyTargetListPropagatesNowhere(): void
    {
        $configuration = new Configuration();
        $configuration->tracePropagationTargets = [];

        self::assertFalse($configuration->shouldPropagateTrace('example.com'));
    }

    public function testInitSetsBothPropagationOptions(): void
    {
        ForgeOpsTracker::init(propagateTraces: false, tracePropagationTargets: ['internal.example'], installExceptionHandler: false);

        self::assertFalse(ForgeOpsTracker::configuration()->propagateTraces);
        self::assertSame(['internal.example'], ForgeOpsTracker::configuration()->tracePropagationTargets);
    }

    public function testErrorsCapturedDuringARequestCarryItsTraceIdNameAndEndpointUnscrubbed(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);

        ForgeOpsTracker::startTrace(self::HEADER);
        // An email-shaped route segment would be scrubbed anywhere else in the payload.
        ForgeOpsTracker::setRequestRoute('GET users/{a@b.co}', 'GET /users/{a@b.co}');
        ForgeOpsTracker::captureException(new RuntimeException('boom'));
        ForgeOpsTracker::finishTrace('GET users/{a@b.co}', microtime(true), 5.0);

        self::assertSame(self::TRACE_ID, $events[0]['trace_id']);
        self::assertSame('GET users/{a@b.co}', $events[0]['transaction_name']);
        self::assertSame('GET /users/{a@b.co}', $events[0]['endpoint']);
    }

    public function testErrorsOutsideARequestAreUnchanged(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);

        ForgeOpsTracker::captureException(new RuntimeException('boom'));
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 5.0);
        ForgeOpsTracker::captureException(new RuntimeException('after'));

        foreach ($events as $event) {
            self::assertArrayNotHasKey('trace_id', $event);
            self::assertArrayNotHasKey('transaction_name', $event);
            self::assertArrayNotHasKey('endpoint', $event);
        }
        self::assertNull(ForgeOpsTracker::currentTraceId());
    }

    public function testLeavesOutAnEndpointNobodySet(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::captureException(new RuntimeException('boom'));

        self::assertSame(ForgeOpsTracker::currentTraceId(), $events[0]['trace_id']);
        self::assertArrayNotHasKey('endpoint', $events[0]);
        self::assertArrayNotHasKey('transaction_name', $events[0]);
    }

    public function testAnEndpointClosureIsOnlyResolvedWhenAnErrorNeedsIt(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        $calls = 0;

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::setRequestRoute('GET order_show', function () use (&$calls): string {
            $calls++;

            return 'GET /orders/{id}';
        });
        self::assertSame(0, $calls);
        ForgeOpsTracker::captureException(new RuntimeException('one'));
        ForgeOpsTracker::captureException(new RuntimeException('two'));

        self::assertSame(1, $calls);
        self::assertSame('GET /orders/{id}', $events[1]['endpoint']);
    }

    public function testATraceIdExistsEvenWithTracingOff(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, tracing: false);

        ForgeOpsTracker::startTrace(self::HEADER);
        self::assertSame(self::TRACE_ID, ForgeOpsTracker::currentTraceId());
        ForgeOpsTracker::captureException(new RuntimeException('boom'));
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 5000.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame(self::TRACE_ID, $events[0]['trace_id']);
        self::assertSame([], $traces);
    }

    public function testNothingStartsWhenTheClientIsNotEnabled(): void
    {
        ForgeOpsTracker::init(dsn: 'https://key@tracker.example.com/api/v1/events', environment: 'development', installExceptionHandler: false);
        ForgeOpsTracker::startTrace(self::HEADER);

        self::assertNull(ForgeOpsTracker::currentTraceId());
    }

    public function testAContinuedTracesRootSpanPointsAtTheCallersSpan(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, threshold: 0.01);

        ForgeOpsTracker::startTrace(self::HEADER);
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 50.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame(self::TRACE_ID, $traces[0]['trace_id']);
        self::assertSame(self::PARENT_ID, $traces[0]['spans'][0]['parent_span_id']);
    }

    public function testAFreshTracesRootSpanHasNoParent(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, threshold: 0.01);

        ForgeOpsTracker::startTrace('00-ffffffffffffffffffffffffffffffff-zzzzzzzzzzzzzzzz-01');
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 50.0);
        ForgeOpsTracker::flushSpans();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $traces[0]['trace_id']);
        self::assertNull($traces[0]['spans'][0]['parent_span_id']);
    }

    public function testAFastRequestThatErroredStillSendsItsTrace(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);

        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::captureException(new RuntimeException('handled'));
        ForgeOpsTracker::finishTrace('GET fast', microtime(true), 1.0);
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::finishTrace('GET fast and fine', microtime(true), 1.0);
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $traces);
        self::assertSame('GET fast', $traces[0]['spans'][0]['name']);
        self::assertSame($events[0]['trace_id'], $traces[0]['trace_id']);
    }

    public function testASnapshottedExceptionReportedAfterTheRequestEndedKeepsItsContext(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        $failure = new RuntimeException('escaped');

        ForgeOpsTracker::startTrace(self::HEADER);
        ForgeOpsTracker::setRequestRoute('POST checkout', 'POST /checkout');
        ForgeOpsTracker::setUser(id: '7');
        ForgeOpsTracker::addBreadcrumb('clicked pay');
        ForgeOpsTracker::snapshotOnto($failure);
        ForgeOpsTracker::finishTrace('POST checkout', microtime(true), 1.0);
        ForgeOpsTracker::setUser();
        ForgeOpsTracker::endBreadcrumbTrail();
        ForgeOpsTracker::flushSpans();

        ForgeOpsTracker::captureException($failure);
        ForgeOpsTracker::captureException(new RuntimeException('unrelated'));

        self::assertSame(self::TRACE_ID, $events[0]['trace_id']);
        self::assertSame('POST checkout', $events[0]['transaction_name']);
        self::assertSame('POST /checkout', $events[0]['endpoint']);
        self::assertSame(['id' => '7'], $events[0]['user']);
        self::assertSame('clicked pay', $events[0]['breadcrumbs'][0]['message']);
        self::assertArrayNotHasKey('trace_id', $events[1]);
        // Snapshotting marks the request errored, so its fast trace was sent too.
        self::assertCount(1, $traces);
    }

    public function testHttpSpanHandsOverATraceparentNamingItsOwnRecordedSpan(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        $sent = null;

        ForgeOpsTracker::startTrace(self::HEADER);
        $result = ForgeOpsTracker::httpSpan('post', 'https://Payments.Example.com/charges/42?token=x', function (array $headers) use (&$sent): string {
            $sent = $headers;

            return 'response';
        }, ['attempt' => 1]);
        ForgeOpsTracker::captureException(new RuntimeException('boom'));
        ForgeOpsTracker::finishTrace('POST checkout', microtime(true), 1.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame('response', $result);
        $parsed = TraceParent::parse($sent['traceparent']);
        self::assertSame(self::TRACE_ID, $parsed['trace_id']);
        $http = $traces[0]['spans'][1];
        self::assertSame('POST payments.example.com', $http['name']);
        self::assertSame('http', $http['kind']);
        self::assertSame($parsed['parent_span_id'], $http['span_id']);
        self::assertSame($traces[0]['spans'][0]['span_id'], $http['parent_span_id']);
    }

    public function testHttpSpanRecordsAndRethrowsWhenTheCallThrows(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, threshold: 0.0);
        $failure = new RuntimeException('connection refused');

        ForgeOpsTracker::startTrace();
        try {
            ForgeOpsTracker::httpSpan('GET', 'https://api.example.com/', function () use ($failure): never {
                throw $failure;
            });
            self::fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 1.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame('GET api.example.com', $traces[0]['spans'][1]['name']);
    }

    public function testHttpSpanSendsNoHeaderToAHostOutsideTheTargets(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces);
        ForgeOpsTracker::configuration()->tracePropagationTargets = ['internal.example'];

        ForgeOpsTracker::startTrace();
        $outside = ForgeOpsTracker::httpSpan('GET', 'https://api.thirdparty.test/x', fn (array $headers) => $headers);
        $inside = ForgeOpsTracker::httpSpan('GET', 'https://orders.internal.example/x', fn (array $headers) => $headers);
        ForgeOpsTracker::configuration()->propagateTraces = false;
        $off = ForgeOpsTracker::httpSpan('GET', 'https://orders.internal.example/x', fn (array $headers) => $headers);

        self::assertSame([], $outside);
        self::assertArrayHasKey('traceparent', $inside);
        self::assertSame([], $off);
    }

    public function testHttpSpanStillPropagatesWithTracingOffButRecordsNothing(): void
    {
        $events = [];
        $traces = [];
        $this->captureEventsAndTraces($events, $traces, tracing: false);

        ForgeOpsTracker::startTrace(self::HEADER);
        $headers = ForgeOpsTracker::httpSpan('GET', 'https://api.example.com/', fn (array $headers) => $headers);
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 5000.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame(self::TRACE_ID, TraceParent::parse($headers['traceparent'])['trace_id']);
        self::assertSame([], $traces);
    }

    public function testHttpSpanOutsideATraceJustRunsTheCallWithNoHeaders(): void
    {
        self::assertSame([], ForgeOpsTracker::httpSpan('GET', 'https://api.example.com/', fn (array $headers) => $headers));
    }
}
