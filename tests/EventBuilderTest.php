<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\Configuration;
use ForgeOps\Tracker\EventBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EventBuilderTest extends TestCase
{
    private function configuration(): Configuration
    {
        $config = new Configuration();
        $config->environment = 'production';
        $config->release = 'abc123';
        $config->serverName = 'web-1';

        return $config;
    }

    private function raiseAndCapture(): RuntimeException
    {
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $e) {
            return $e;
        }
    }

    public function testBuildsAPayloadWithTheExceptionClassMessageAndConfiguredMetadata(): void
    {
        $builder = new EventBuilder($this->configuration());
        $error = $this->raiseAndCapture();

        $payload = $builder->build($error, ['url' => 'https://example.com']);

        self::assertSame('RuntimeException', $payload['exception_class']);
        self::assertSame('boom', $payload['message']);
        self::assertSame('production', $payload['environment']);
        self::assertSame('abc123', $payload['release']);
        self::assertSame('web-1', $payload['server_name']);
        self::assertSame(['url' => 'https://example.com'], $payload['context']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['occurred_at']);
    }

    public function testParsesRealStackFramesWithFileLineAndMethod(): void
    {
        $builder = new EventBuilder($this->configuration());
        $error = $this->raiseAndCapture();

        $frames = $builder->build($error)['backtrace'];

        self::assertNotEmpty($frames);
        // The innermost frame's file/line is the throwable's own -- where
        // the `throw` statement itself executed (this file), paired with
        // the *next* trace entry's function name (raiseAndCapture), per
        // PHP's call-site-shifted trace format verified directly against
        // a real nested throw.
        self::assertSame(__FILE__, $frames[0]['file']);
        self::assertSame('raiseAndCapture', $this->methodNameOnly($frames[0]['method']));
        self::assertIsInt($frames[0]['line']);
    }

    public function testMarksAFrameUnderAppRootAsInApp(): void
    {
        $config = $this->configuration();
        $config->appRoot = __DIR__;
        $error = $this->raiseAndCapture();

        $frames = (new EventBuilder($config))->build($error)['backtrace'];

        self::assertNotEmpty(array_filter($frames, static fn (array $frame): bool => $frame['in_app']));
    }

    public function testMarksEveryFrameAsNotInAppWhenAppRootDoesNotMatch(): void
    {
        $config = $this->configuration();
        $config->appRoot = '/definitely/not/here';
        $error = $this->raiseAndCapture();

        $frames = (new EventBuilder($config))->build($error)['backtrace'];

        self::assertEmpty(array_filter($frames, static fn (array $frame): bool => $frame['in_app']));
    }

    public function testCapturesTheBacktraceAtConstructionTimeEvenBeforeBeingThrown(): void
    {
        // Unlike the Ruby/.NET/Python clients, PHP's Exception captures
        // its backtrace at *construction* time (verified directly, not
        // assumed) -- so there's no equivalent "empty backtrace when
        // never thrown" case to test here; a constructed-but-unthrown
        // exception still has a real, non-empty trace.
        $builder = new EventBuilder($this->configuration());
        $error = new RuntimeException('boom'); // constructed, never thrown

        $frames = $builder->build($error)['backtrace'];

        self::assertNotEmpty($frames);
    }

    public function testScrubsLikelyPiiOutOfTheMessageAndContextByDefault(): void
    {
        $builder = new EventBuilder($this->configuration());
        try {
            throw new RuntimeException('failed for user@example.com');
        } catch (RuntimeException $error) {
            $payload = $builder->build($error, [
                'user' => ['email' => 'ada@example.com', 'password' => 'hunter2'],
            ]);
        }

        self::assertSame('failed for [EMAIL FILTERED]', $payload['message']);
        self::assertSame(
            ['user' => ['email' => '[EMAIL FILTERED]', 'password' => '[FILTERED]']],
            $payload['context']
        );
    }

    public function testLeavesThePayloadUntouchedWhenScrubPiiIsDisabled(): void
    {
        $config = $this->configuration();
        $config->scrubPii = false;
        $builder = new EventBuilder($config);
        try {
            throw new RuntimeException('failed for user@example.com');
        } catch (RuntimeException $error) {
            $payload = $builder->build($error, ['email' => 'ada@example.com']);
        }

        self::assertSame('failed for user@example.com', $payload['message']);
        self::assertSame(['email' => 'ada@example.com'], $payload['context']);
    }

    // --- Source context capture -------------------------------------------

    /**
     * Writes a small, real, requirable PHP fixture file where line
     * $throwAtLine is a `throw` statement and every other line is an inert
     * comment -- so requiring it produces a real Throwable whose
     * getFile()/getLine() point at that exact line, with real, known
     * content surrounding it to assert against.
     *
     * @return array{0: string, 1: string[]} the fixture's path, and its
     *     lines exactly as written (1:1 with the file's real line numbers).
     */
    private function writeSourceFile(int $lineCount, int $throwAtLine): array
    {
        $lines = [];
        for ($n = 1; $n <= $lineCount; $n++) {
            $statement = $n === $throwAtLine ? "throw new \\RuntimeException('boom');" : "// line {$n}";
            $lines[$n - 1] = $n === 1 ? "<?php {$statement}" : $statement;
        }

        $path = tempnam(sys_get_temp_dir(), 'forge_ops_source_') . '.php';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return [$path, $lines];
    }

    private function requireAndCatch(string $path): RuntimeException
    {
        try {
            require $path;
        } catch (RuntimeException $e) {
            return $e;
        }

        self::fail("fixture at {$path} did not throw");
    }

    public function testAttachesPreContextContextLineAndPostContextAroundAnInAppFramesCulpritLineByDefault(): void
    {
        [$path, $lines] = $this->writeSourceFile(20, 10);
        $config = $this->configuration();
        $config->appRoot = dirname($path);

        $frame = (new EventBuilder($config))->build($this->requireAndCatch($path))['backtrace'][0];

        self::assertSame($lines[9], $frame['context_line']);
        self::assertSame(array_slice($lines, 4, 5), $frame['pre_context']);
        self::assertSame(array_slice($lines, 10, 5), $frame['post_context']);

        unlink($path);
    }

    public function testScrubsLikelyPiiOutOfCapturedSourceContextTooByDefault(): void
    {
        [$path, ] = $this->writeSourceFile(3, 2);
        $config = $this->configuration();
        $config->appRoot = dirname($path);
        file_put_contents($path, str_replace(
            '// line 1',
            '// contact user@example.com',
            file_get_contents($path)
        ));

        $frame = (new EventBuilder($config))->build($this->requireAndCatch($path))['backtrace'][0];

        self::assertSame('<?php // contact [EMAIL FILTERED]', $frame['pre_context'][0]);

        unlink($path);
    }

    public function testClampsPreContextAndPostContextAtTheStartAndEndOfTheFileRatherThanFailing(): void
    {
        [$firstPath, $firstLines] = $this->writeSourceFile(3, 1);
        [$lastPath, $lastLines] = $this->writeSourceFile(3, 3);
        $config = $this->configuration();
        // dirname(), not sys_get_temp_dir() directly: on some platforms the
        // latter differs from the (symlink-resolved) directory a real file
        // path built from it reports, which would fail the in_app check.
        $config->appRoot = dirname($firstPath);

        $firstFrame = (new EventBuilder($config))->build($this->requireAndCatch($firstPath))['backtrace'][0];
        $lastFrame = (new EventBuilder($config))->build($this->requireAndCatch($lastPath))['backtrace'][0];

        self::assertSame([], $firstFrame['pre_context']);
        self::assertSame(array_slice($firstLines, 1, 2), $firstFrame['post_context']);
        self::assertSame(array_slice($lastLines, 0, 2), $lastFrame['pre_context']);
        self::assertSame([], $lastFrame['post_context']);

        unlink($firstPath);
        unlink($lastPath);
    }

    public function testTruncatesAnIndividualLineLongerThanMaxContextLineLength(): void
    {
        $overlong = "throw new \\RuntimeException('boom'); // " . str_repeat('x', 600);
        $path = tempnam(sys_get_temp_dir(), 'forge_ops_source_') . '.php';
        file_put_contents($path, "<?php\n{$overlong}\n");
        $config = $this->configuration();
        $config->appRoot = dirname($path);

        $frame = (new EventBuilder($config))->build($this->requireAndCatch($path))['backtrace'][0];

        self::assertSame(substr($overlong, 0, 500) . '...', $frame['context_line']);

        unlink($path);
    }

    public function testNeverAttachesContextToAFrameOutsideAppRoot(): void
    {
        [$path, ] = $this->writeSourceFile(20, 10);
        $config = $this->configuration();
        $config->appRoot = '/definitely/not/here';

        $frame = (new EventBuilder($config))->build($this->requireAndCatch($path))['backtrace'][0];

        self::assertArrayNotHasKey('context_line', $frame);
        self::assertArrayNotHasKey('pre_context', $frame);
        self::assertArrayNotHasKey('post_context', $frame);

        unlink($path);
    }

    public function testLeavesAFrameUntouchedWithNoFileReadAttemptedWhenCaptureSourceContextIsDisabled(): void
    {
        [$path, ] = $this->writeSourceFile(20, 10);
        $config = $this->configuration();
        $config->appRoot = dirname($path);
        $config->captureSourceContext = false;

        $builder = $this->getMockBuilder(EventBuilder::class)
            ->setConstructorArgs([$config])
            ->onlyMethods(['readSourceLines'])
            ->getMock();
        $builder->expects(self::never())->method('readSourceLines');

        $frame = $builder->build($this->requireAndCatch($path))['backtrace'][0];

        self::assertArrayNotHasKey('context_line', $frame);

        unlink($path);
    }

    public function testLeavesAFrameUntouchedWhenTheRecordedFileCannotBeRead(): void
    {
        [$path, ] = $this->writeSourceFile(20, 10);
        $config = $this->configuration();
        $config->appRoot = dirname($path);
        $error = $this->requireAndCatch($path);
        unlink($path); // gone by the time EventBuilder tries to read it

        $frame = (new EventBuilder($config))->build($error)['backtrace'][0];

        self::assertArrayNotHasKey('context_line', $frame);
    }

    private function methodNameOnly(?string $method): ?string
    {
        if ($method === null) {
            return null;
        }
        // Strips a "Class->" / "Class::" prefix, if any, leaving just the
        // bare method/function name for the assertion above.
        $pos = strrpos($method, '->') ?: strrpos($method, '::');

        return $pos === false ? $method : substr($method, $pos + 2);
    }
}
