<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\PerformanceFlusher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PerformanceFlusherTest extends TestCase
{
    private function configuration(): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->enabledEnvironments = ['production'];
        $config->environment = 'production';
        $config->release = '1.2.3';

        return $config;
    }

    public function testDeliversOnePerformanceSampleForTheCurrentRequest(): void
    {
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverPerformanceSamples')->willReturnCallback(function (array $samples) use (&$delivered): bool {
            $delivered[] = $samples;

            return true;
        });

        $flusher = new PerformanceFlusher($this->configuration(), $client);
        $flusher->record('GET users/{id}', 42.5);
        $flusher->flush();

        self::assertCount(1, $delivered);
        self::assertCount(1, $delivered[0]);
        self::assertSame('GET users/{id}', $delivered[0][0]['transaction_name']);
        self::assertSame(1, $delivered[0][0]['request_count']);
        self::assertSame(42.5, $delivered[0][0]['duration_sum_ms']);
        self::assertSame(42.5, $delivered[0][0]['max_duration_ms']);
        self::assertSame('1.2.3', $delivered[0][0]['release']);
        self::assertSame('production', $delivered[0][0]['environment']);
    }

    public function testDoesNothingOnAFlushWithNothingRecorded(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverPerformanceSamples');

        $flusher = new PerformanceFlusher($this->configuration(), $client);
        $flusher->flush();
    }

    public function testARepeatFlushDoesNotRedeliverTheSameSample(): void
    {
        $callCount = 0;
        $client = $this->createMock(Client::class);
        $client->method('deliverPerformanceSamples')->willReturnCallback(function () use (&$callCount): bool {
            $callCount++;

            return true;
        });

        $flusher = new PerformanceFlusher($this->configuration(), $client);
        $flusher->record('GET users/{id}', 42.5);
        $flusher->flush();
        $flusher->flush(); // nothing new recorded, must not redeliver

        self::assertSame(1, $callCount);
    }

    public function testRecoversFromTheClientThrowingInsteadOfReturning(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('deliverPerformanceSamples')->willThrowException(new RuntimeException('boom'));

        $flusher = new PerformanceFlusher($this->configuration(), $client);
        $flusher->record('GET users/{id}', 42.5);
        $flusher->flush(); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testDoesNotRecordWhenTrackPerformanceIsDisabled(): void
    {
        $config = $this->configuration();
        $config->trackPerformance = false;
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverPerformanceSamples');

        $flusher = new PerformanceFlusher($config, $client);
        $flusher->record('GET users/{id}', 42.5);
        $flusher->flush();
    }

    public function testDoesNotRecordWithoutAValidDsnConfigured(): void
    {
        $config = $this->configuration();
        $config->dsn = null;
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverPerformanceSamples');

        $flusher = new PerformanceFlusher($config, $client);
        $flusher->record('GET users/{id}', 42.5);
        $flusher->flush();
    }

    public function testDeliversAOneBucketLatencyHistogramWithEachSample(): void
    {
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverPerformanceSamples')->willReturnCallback(function (array $samples) use (&$delivered): bool {
            $delivered = $samples;

            return true;
        });

        $flusher = new PerformanceFlusher($this->configuration(), $client);
        $flusher->record('GET slow', 700.0);
        $flusher->record('GET very-slow', 12000.0, 'controller');
        $flusher->flush();

        // JSON is what actually goes over the wire, so assert on that, not just the PHP array:
        // a numeric-string key must still come out as an object key, never a JSON list.
        self::assertSame('{"1000":1}', json_encode($delivered[0]['histogram']));
        self::assertSame('{"inf":1}', json_encode($delivered[1]['histogram']));
    }
}
