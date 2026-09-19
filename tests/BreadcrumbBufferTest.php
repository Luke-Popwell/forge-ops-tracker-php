<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\BreadcrumbBuffer;
use ForgeOps\Tracker\Configuration;
use PHPUnit\Framework\TestCase;

final class BreadcrumbBufferTest extends TestCase
{
    private function configuration(int $maxBreadcrumbs = 3): Configuration
    {
        $configuration = new Configuration();
        $configuration->maxBreadcrumbs = $maxBreadcrumbs;

        return $configuration;
    }

    public function testReturnsEntriesInInsertionOrderEachCarryingTheGivenFieldsPlusATimestamp(): void
    {
        $buffer = new BreadcrumbBuffer($this->configuration());

        $buffer->add('query', 'User Load', 'info', ['duration_ms' => 1.2]);

        $entry = $buffer->all()[0];
        self::assertSame('query', $entry['category']);
        self::assertSame('User Load', $entry['message']);
        self::assertSame('info', $entry['level']);
        self::assertSame(['duration_ms' => 1.2], $entry['data']);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $entry['timestamp']);
    }

    public function testDefaultsLevelToInfoAndDataToAnEmptyArrayWhenNotGiven(): void
    {
        $buffer = new BreadcrumbBuffer($this->configuration());

        $buffer->add('custom', 'checkpoint');

        $entry = $buffer->all()[0];
        self::assertSame('info', $entry['level']);
        self::assertSame([], $entry['data']);
    }

    public function testDropsTheOldestEntryOnceMaxBreadcrumbsIsExceeded(): void
    {
        $buffer = new BreadcrumbBuffer($this->configuration(3));

        for ($n = 0; $n < 4; $n++) {
            $buffer->add('custom', "entry {$n}");
        }

        self::assertSame(
            ['entry 1', 'entry 2', 'entry 3'],
            array_column($buffer->all(), 'message'),
        );
    }

    public function testReReadsMaxBreadcrumbsOnEveryAddNotJustAtConstruction(): void
    {
        $configuration = $this->configuration(3);
        $buffer = new BreadcrumbBuffer($configuration);

        $buffer->add('custom', 'one');
        $buffer->add('custom', 'two');
        $configuration->maxBreadcrumbs = 1;
        $buffer->add('custom', 'three');

        self::assertSame(['three'], array_column($buffer->all(), 'message'));
    }

    public function testAddsNothingAtAllWhenMaxBreadcrumbsIsZero(): void
    {
        $configuration = $this->configuration(0);
        $buffer = new BreadcrumbBuffer($configuration);

        $buffer->add('custom', 'one');

        self::assertSame([], $buffer->all());
    }

    public function testNegativeMaxBreadcrumbsIsTreatedAsZeroNotAsUnbounded(): void
    {
        $configuration = $this->configuration(-5);
        $buffer = new BreadcrumbBuffer($configuration);

        $buffer->add('custom', 'one');

        self::assertSame([], $buffer->all());
    }

    public function testAllReturnsACopyNotTheInternalArray(): void
    {
        $buffer = new BreadcrumbBuffer($this->configuration());
        $buffer->add('custom', 'one');

        $snapshot = $buffer->all();
        $snapshot[] = ['category' => 'custom', 'message' => 'mutated externally'];

        self::assertCount(1, $buffer->all());
    }
}
