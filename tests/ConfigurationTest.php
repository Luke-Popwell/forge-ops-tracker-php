<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private function configuration(): Configuration
    {
        $config = new Configuration();
        $config->dsn = null;
        $config->enabledEnvironments = ['production', 'staging'];
        $config->environment = 'production';

        return $config;
    }

    public function testExtractsApiKeyAndCredentialFreeIngestionUriFromDsn(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://secret-key@tracker.example.com/api/v1/events';

        self::assertSame('secret-key', $config->apiKey());
        self::assertSame('https://tracker.example.com/api/v1/events', $config->ingestionUri());
    }

    public function testReturnsNullForBothWhenThereIsNoDsn(): void
    {
        $config = $this->configuration();
        $config->dsn = null;

        self::assertNull($config->apiKey());
        self::assertNull($config->ingestionUri());
    }

    public function testReturnsNullForBothWhenTheDsnIsMalformed(): void
    {
        $config = $this->configuration();
        $config->dsn = 'not a uri :: at all';

        self::assertNull($config->apiKey());
        self::assertNull($config->ingestionUri());
    }

    public function testIsEnabledWithAValidDsnInAnEnabledEnvironment(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->environment = 'production';

        self::assertTrue($config->isEnabled());
    }

    public function testIsNotEnabledWithNoDsnConfigured(): void
    {
        $config = $this->configuration();
        $config->dsn = null;

        self::assertFalse($config->isEnabled());
    }

    public function testIsNotEnabledWhenTheDsnHasNoApiKey(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://tracker.example.com/api/v1/events';

        self::assertFalse($config->isEnabled());
    }

    public function testIsNotEnabledOutsideTheConfiguredEnabledEnvironments(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://key@tracker.example.com/api/v1/events';
        $config->environment = 'development';

        self::assertFalse($config->isEnabled());
    }

    public function testTrackSessionsDefaultsToTrue(): void
    {
        self::assertTrue((new Configuration())->trackSessions);
    }

    public function testSessionCheckinsUriSwapsEventsForSessionCheckins(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://secret-key@tracker.example.com/api/v1/events';

        self::assertSame('https://tracker.example.com/api/v1/session_checkins', $config->sessionCheckinsUri());
    }

    public function testSessionCheckinsUriIsNullWithNoDsn(): void
    {
        $config = $this->configuration();
        $config->dsn = null;

        self::assertNull($config->sessionCheckinsUri());
    }

    public function testTrackPerformanceDefaultsToTrue(): void
    {
        self::assertTrue((new Configuration())->trackPerformance);
    }

    public function testPerformanceSamplesUriSwapsEventsForPerformanceSamples(): void
    {
        $config = $this->configuration();
        $config->dsn = 'https://secret-key@tracker.example.com/api/v1/events';

        self::assertSame('https://tracker.example.com/api/v1/performance_samples', $config->performanceSamplesUri());
    }

    public function testPerformanceSamplesUriIsNullWithNoDsn(): void
    {
        $config = $this->configuration();
        $config->dsn = null;

        self::assertNull($config->performanceSamplesUri());
    }
}
