<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\HistogramBucketer;
use PHPUnit\Framework\TestCase;

final class HistogramBucketerTest extends TestCase
{
    public function testReturnsTheSmallestBoundaryADurationFitsUnderAsAString(): void
    {
        self::assertSame('50', HistogramBucketer::bucketFor(10));
        self::assertSame('50', HistogramBucketer::bucketFor(50));
        self::assertSame('100', HistogramBucketer::bucketFor(50.5));
        self::assertSame('5000', HistogramBucketer::bucketFor(4999));
    }

    public function testReturnsInfForAnythingLargerThanTheLargestBoundary(): void
    {
        self::assertSame('inf', HistogramBucketer::bucketFor(10001));
        self::assertSame('inf', HistogramBucketer::bucketFor(1000000));
    }

    public function testPutsADurationExactlyOnABoundaryIntoThatBoundarysOwnBucket(): void
    {
        foreach (HistogramBucketer::BOUNDARIES_MS as $boundary) {
            self::assertSame((string) $boundary, HistogramBucketer::bucketFor($boundary));
        }
    }

    public function testBoundariesMatchTheServersHistogramPercentile(): void
    {
        // app/services/histogram_percentile.rb and every other SDK must agree on this exact list.
        self::assertSame([50, 100, 250, 500, 1000, 2500, 5000, 10000], HistogramBucketer::BOUNDARIES_MS);
    }
}
