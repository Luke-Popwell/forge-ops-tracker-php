<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\DeliveryQueue;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeliveryQueueTest extends TestCase
{
    private function configuration(int $queueSize = 1): Configuration
    {
        $config = new Configuration();
        $config->queueSize = $queueSize;

        return $config;
    }

    public function testFlushDeliversEveryQueuedPayloadViaTheClient(): void
    {
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliver')->willReturnCallback(function (array $payload) use (&$delivered): bool {
            $delivered[] = $payload;

            return true;
        });

        $queue = new DeliveryQueue($this->configuration(queueSize: 10), $client);
        $queue->push(['exception_class' => 'RuntimeError']);
        $queue->flush();

        self::assertSame([['exception_class' => 'RuntimeError']], $delivered);
    }

    public function testDropsAPayloadWithoutBlockingWhenTheQueueIsAlreadyFull(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliver'); // never flushed in this test

        $queue = new DeliveryQueue($this->configuration(queueSize: 1), $client);

        self::assertTrue($queue->push(['n' => 1]));
        self::assertFalse($queue->push(['n' => 2])); // capacity (1) already used, dropped immediately
    }

    public function testFlushClearsThePendingQueueSoARepeatFlushDoesNotRedeliver(): void
    {
        $callCount = 0;
        $client = $this->createMock(Client::class);
        $client->method('deliver')->willReturnCallback(function () use (&$callCount): bool {
            $callCount++;

            return true;
        });

        $queue = new DeliveryQueue($this->configuration(queueSize: 10), $client);
        $queue->push(['n' => 1]);
        $queue->flush();
        $queue->flush(); // nothing left queued -- must not redeliver

        self::assertSame(1, $callCount);
    }

    public function testRecoversFromTheClientThrowingInsteadOfReturning(): void
    {
        $delivered = [];
        $callCount = 0;
        $client = $this->createMock(Client::class);
        $client->method('deliver')->willReturnCallback(function (array $payload) use (&$delivered, &$callCount): bool {
            $callCount++;
            if ($callCount === 1) {
                throw new RuntimeException('boom');
            }
            $delivered[] = $payload;

            return true;
        });

        $queue = new DeliveryQueue($this->configuration(queueSize: 10), $client);
        $queue->push(['n' => 1]); // will throw inside flush(); must not stop the second delivery
        $queue->push(['n' => 2]);
        $queue->flush();

        self::assertSame([['n' => 2]], $delivered);
    }
}
