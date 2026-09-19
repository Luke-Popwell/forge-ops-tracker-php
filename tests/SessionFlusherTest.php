<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Client;
use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\SessionFlusher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionFlusherTest extends TestCase
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

    public function testDeliversASessionCheckinForACrashFreeRequest(): void
    {
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverSessionCheckin')->willReturnCallback(function (array $payload) use (&$delivered): bool {
            $delivered[] = $payload;

            return true;
        });

        $flusher = new SessionFlusher($this->configuration(), $client);
        $flusher->recordSession(false);
        $flusher->flush();

        self::assertCount(1, $delivered);
        self::assertSame(1, $delivered[0]['sessions_count']);
        self::assertSame(0, $delivered[0]['crashed_sessions_count']);
        self::assertSame('1.2.3', $delivered[0]['release']);
        self::assertSame('production', $delivered[0]['environment']);
    }

    public function testDeliversACrashedSessionCheckinWhenTheRequestCrashed(): void
    {
        $delivered = [];
        $client = $this->createMock(Client::class);
        $client->method('deliverSessionCheckin')->willReturnCallback(function (array $payload) use (&$delivered): bool {
            $delivered[] = $payload;

            return true;
        });

        $flusher = new SessionFlusher($this->configuration(), $client);
        $flusher->recordSession(true);
        $flusher->flush();

        self::assertSame(1, $delivered[0]['crashed_sessions_count']);
    }

    public function testDoesNothingOnAFlushWithNoSessionRecorded(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverSessionCheckin');

        $flusher = new SessionFlusher($this->configuration(), $client);
        $flusher->flush();
    }

    public function testARepeatFlushDoesNotRedeliverTheSameSession(): void
    {
        $callCount = 0;
        $client = $this->createMock(Client::class);
        $client->method('deliverSessionCheckin')->willReturnCallback(function () use (&$callCount): bool {
            $callCount++;

            return true;
        });

        $flusher = new SessionFlusher($this->configuration(), $client);
        $flusher->recordSession(false);
        $flusher->flush();
        $flusher->flush(); // nothing new recorded, must not redeliver

        self::assertSame(1, $callCount);
    }

    public function testRecoversFromTheClientThrowingInsteadOfReturning(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('deliverSessionCheckin')->willThrowException(new RuntimeException('boom'));

        $flusher = new SessionFlusher($this->configuration(), $client);
        $flusher->recordSession(false);
        $flusher->flush(); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testDoesNotRecordWhenTrackSessionsIsDisabled(): void
    {
        $config = $this->configuration();
        $config->trackSessions = false;
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverSessionCheckin');

        $flusher = new SessionFlusher($config, $client);
        $flusher->recordSession(false);
        $flusher->flush();
    }

    public function testDoesNotRecordWithoutAValidDsnConfigured(): void
    {
        $config = $this->configuration();
        $config->dsn = null;
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('deliverSessionCheckin');

        $flusher = new SessionFlusher($config, $client);
        $flusher->recordSession(false);
        $flusher->flush();
    }
}
