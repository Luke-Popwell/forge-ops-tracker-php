<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\DeliveryQueue;
use ForgeOps\Tracker\EventBuilder;
use ForgeOps\Tracker\Reporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReporterTest extends TestCase
{
    private function enabledConfiguration(): Configuration
    {
        $config = new Configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->enabledEnvironments = ['production'];
        $config->environment = 'production';

        return $config;
    }

    public function testBuildsAndEnqueuesAPayloadWhenReportingIsEnabled(): void
    {
        $configuration = $this->enabledConfiguration();
        $builtPayload = ['exception_class' => 'RuntimeException'];
        $error = new RuntimeException('boom');
        $context = ['a' => 1];

        $eventBuilder = $this->createMock(EventBuilder::class);
        $eventBuilder->expects(self::once())->method('build')->with($error, $context)->willReturn($builtPayload);

        $deliveryQueue = $this->createMock(DeliveryQueue::class);
        $deliveryQueue->expects(self::once())->method('push')->with($builtPayload);

        (new Reporter($configuration, $eventBuilder, $deliveryQueue))->report($error, $context);
    }

    public function testDoesNothingWhenReportingIsDisabled(): void
    {
        $configuration = $this->enabledConfiguration();
        $configuration->environment = 'development'; // not in enabledEnvironments

        $eventBuilder = $this->createMock(EventBuilder::class);
        $eventBuilder->expects(self::never())->method('build');

        $deliveryQueue = $this->createMock(DeliveryQueue::class);
        $deliveryQueue->expects(self::never())->method('push');

        (new Reporter($configuration, $eventBuilder, $deliveryQueue))->report(new RuntimeException('boom'));
    }

    public function testNeverThrowsEvenIfBuildingTheEventFails(): void
    {
        $configuration = $this->enabledConfiguration();

        $eventBuilder = $this->createMock(EventBuilder::class);
        $eventBuilder->method('build')->willThrowException(new RuntimeException('event builder exploded'));

        $deliveryQueue = $this->createMock(DeliveryQueue::class);

        (new Reporter($configuration, $eventBuilder, $deliveryQueue))->report(new RuntimeException('boom'));
        self::assertTrue(true); // reaching here without a thrown exception is the assertion
    }

    public function testNeverThrowsEvenIfEnqueueingFails(): void
    {
        $configuration = $this->enabledConfiguration();

        $eventBuilder = $this->createMock(EventBuilder::class);
        $eventBuilder->method('build')->willReturn([]);

        $deliveryQueue = $this->createMock(DeliveryQueue::class);
        $deliveryQueue->method('push')->willThrowException(new RuntimeException('queue exploded'));

        (new Reporter($configuration, $eventBuilder, $deliveryQueue))->report(new RuntimeException('boom'));
        self::assertTrue(true);
    }
}
