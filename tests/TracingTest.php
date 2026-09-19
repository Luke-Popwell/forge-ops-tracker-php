<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\ForgeOpsTracker;
use ForgeOps\Tracker\SpanBuffer;
use ForgeOps\Tracker\SpanFlusher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class TracingTest extends TestCase
{
    protected function tearDown(): void
    {
        ForgeOpsTracker::resetForTesting();
    }

    private function configuration(float $threshold = 0.01): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->enabledEnvironments = ['production'];
        $config->environment = 'production';
        $config->release = 'abc123';
        $config->traceCaptureThreshold = $threshold;

        return $config;
    }

    /** @return array<int, array<string, mixed>> */
    private function initWithFakeClient(array &$delivered, bool $tracing = true): void
    {
        ForgeOpsTracker::init(
            dsn: 'https://key@tracker.example.com/api/v1/events',
            environment: 'production',
            release: 'abc123',
            enabledEnvironments: ['production'],
            trackTracing: $tracing,
            traceCaptureThreshold: 0.01,
            installExceptionHandler: false,
        );

        $client = $this->createMock(Client::class);
        $client->method('deliverSpans')->willReturnCallback(function (array $trace) use (&$delivered): bool {
            $delivered[] = $trace;

            return true;
        });
        (new ReflectionProperty(ForgeOpsTracker::class, 'spanFlusher'))
            ->setValue(null, new SpanFlusher(ForgeOpsTracker::configuration(), $client));
    }

    public function testSpansUriSwapsTheTrailingEventsSegment(): void
    {
        self::assertSame('https://tracker.example.com/api/v1/spans', $this->configuration()->spansUri());
    }

    public function testNestsSpansUnderTheOpenOneAndTheRootWithTheWireShape(): void
    {
        $buffer = new SpanBuffer($this->configuration());
        $outer = $buffer->open();
        $buffer->recordLeaf('SELECT users', 'database', microtime(true), 3.0);
        $buffer->finish($outer, 'charge', 'service', microtime(true), 20.0, ['k' => 'v']);
        $buffer->recordLeaf('sibling', 'database', microtime(true), 1.0);

        $payload = $buffer->finishTrace('GET x', microtime(true), 1500.0);
        $byName = [];
        foreach ($payload['spans'] as $span) {
            $byName[$span['name']] = $span;
        }

        self::assertSame(32, strlen($payload['trace_id']));
        self::assertNull($byName['GET x']['parent_span_id']);
        self::assertSame('controller', $byName['GET x']['kind']);
        self::assertSame($byName['charge']['span_id'], $byName['SELECT users']['parent_span_id']);
        self::assertSame($byName['GET x']['span_id'], $byName['charge']['parent_span_id']);
        self::assertSame($byName['GET x']['span_id'], $byName['sibling']['parent_span_id']);
        self::assertSame(16, strlen($byName['charge']['span_id']));
        self::assertSame('production', $byName['charge']['environment']);
        self::assertSame('abc123', $byName['charge']['release']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $byName['charge']['started_at']);
        self::assertStringContainsString('"parent_span_id":null', json_encode($payload));
        self::assertStringContainsString('"data":{}', json_encode($byName['GET x']));
    }

    public function testDropsTheTraceWhenTheRootIsUnderTheThreshold(): void
    {
        $buffer = new SpanBuffer($this->configuration(1.0));
        self::assertNull($buffer->finishTrace('GET fast', microtime(true), 999.0));
    }

    public function testSendsAnUnknownKindAsOtherSinceTheServerWouldRejectTheWholeTrace(): void
    {
        $buffer = new SpanBuffer($this->configuration());
        $buffer->recordLeaf('q', 'db', microtime(true), 1.0);
        $buffer->recordLeaf('r', 'database', microtime(true), 1.0);
        $spans = $buffer->finishTrace('root', microtime(true), 2000.0)['spans'];

        self::assertSame('other', $spans[1]['kind']);
        self::assertSame('database', $spans[2]['kind']);
    }

    public function testCapsATraceAtFiveHundredSpansIncludingTheRoot(): void
    {
        $buffer = new SpanBuffer($this->configuration());
        for ($i = 0; $i < 700; $i++) {
            $buffer->recordLeaf('q', 'database', microtime(true), 1.0);
        }

        self::assertCount(500, $buffer->finishTrace('GET x', microtime(true), 2000.0)['spans']);
    }

    public function testDeliversASlowTraceWithNestedSpans(): void
    {
        $delivered = [];
        $this->initWithFakeClient($delivered);

        ForgeOpsTracker::startTrace();
        $result = ForgeOpsTracker::span('charge', function (): string {
            ForgeOpsTracker::recordSpan('SELECT', 'database', microtime(true), 3.0);

            return 'ok';
        }, 'service', ['order' => 1]);
        self::assertSame('ok', $result);
        ForgeOpsTracker::finishTrace('GET /checkout', microtime(true), 250.0);
        ForgeOpsTracker::flushSpans();

        self::assertCount(1, $delivered);
        $names = array_column($delivered[0]['spans'], 'name');
        self::assertSame(['GET /checkout', 'SELECT', 'charge'], $names);
    }

    public function testSpanRecordsAndRethrowsUnchangedWhenTheWorkThrows(): void
    {
        $delivered = [];
        $this->initWithFakeClient($delivered);
        ForgeOpsTracker::startTrace();
        $failure = new RuntimeException('boom');

        try {
            ForgeOpsTracker::span('bad', function () use ($failure): void {
                throw $failure;
            });
            self::fail('expected the exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        ForgeOpsTracker::finishTrace('GET x', microtime(true), 250.0);
        ForgeOpsTracker::flushSpans();
        self::assertContains('bad', array_column($delivered[0]['spans'], 'name'));
    }

    public function testSpanJustRunsTheWorkOutsideATrace(): void
    {
        self::assertSame(7, ForgeOpsTracker::span('free', fn () => 7));
    }

    public function testSendsNothingWhenTheRootIsUnderTheThreshold(): void
    {
        $delivered = [];
        $this->initWithFakeClient($delivered);
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::finishTrace('GET fast', microtime(true), 1.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame([], $delivered);
    }

    public function testTrackTracingOffDisablesTheWholeFeature(): void
    {
        $delivered = [];
        $this->initWithFakeClient($delivered, tracing: false);
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::span('x', fn () => 1);
        ForgeOpsTracker::finishTrace('GET x', microtime(true), 500.0);
        ForgeOpsTracker::flushSpans();

        self::assertSame([], $delivered);
    }

    public function testFinishTraceClearsTheTraceSoNothingLeaksIntoTheNextRequest(): void
    {
        $delivered = [];
        $this->initWithFakeClient($delivered);
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::finishTrace('first', microtime(true), 250.0);
        ForgeOpsTracker::recordSpan('stray', 'database', microtime(true), 1.0);
        ForgeOpsTracker::startTrace();
        ForgeOpsTracker::finishTrace('second', microtime(true), 250.0);
        ForgeOpsTracker::flushSpans();

        self::assertCount(2, $delivered);
        self::assertSame(['second'], array_column($delivered[1]['spans'], 'name'));
    }

    public function testAFullQueueDropsTheTraceInsteadOfBlocking(): void
    {
        $config = $this->configuration();
        $config->queueSize = 1;
        $flusher = new SpanFlusher($config, $this->createMock(Client::class));

        self::assertTrue($flusher->push(['trace_id' => 'a', 'spans' => []]));
        self::assertFalse($flusher->push(['trace_id' => 'b', 'spans' => []]));
    }
}
