<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Turns a raised exception into the payload shape the ingestion API
 * expects. Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/event_builder.rb.
 */
class EventBuilder
{
    private const MAX_FRAMES = 500;

    // How many lines of source to grab on either side of the culprit line
    // (see attachSourceContext()), and the longest a single captured line
    // is allowed to be before getting truncated -- guards against a single
    // pathological minified/generated line ballooning the payload. ForgeOps
    // itself re-truncates on arrival too, the same "don't just trust the
    // client" posture MAX_FRAMES already gets on the server side.
    private const CONTEXT_LINES = 5;
    private const MAX_CONTEXT_LINE_LENGTH = 500;

    public function __construct(private Configuration $configuration)
    {
    }

    /** @param array<string, mixed> $context */
    public function build(Throwable $throwable, array $context = []): array
    {
        $payload = [
            'exception_class' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'backtrace' => $this->backtrace($throwable),
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'environment' => $this->configuration->environment,
            'release' => $this->configuration->release,
            'server_name' => $this->configuration->serverName,
            'context' => $context,
            'tags' => [],
        ];

        return $this->configuration->scrubPii ? $this->scrub($payload) : $payload;
    }

    // exception_class/occurred_at/environment/release/server_name are
    // left alone -- structured fields this client or the host app sets
    // deliberately, not free text an exception or its context could
    // accidentally spill sensitive data into.
    private function scrub(array $payload): array
    {
        $payload['message'] = PiiScrubber::scrubString($payload['message']);
        $payload['backtrace'] = array_map(static function (array $frame): array {
            $frame['file'] = $frame['file'] !== null ? PiiScrubber::scrubString($frame['file']) : null;
            $frame['method'] = $frame['method'] !== null ? PiiScrubber::scrubString($frame['method']) : null;

            if (array_key_exists('context_line', $frame)) {
                $frame['context_line'] = PiiScrubber::scrubString($frame['context_line']);
                $frame['pre_context'] = array_map(PiiScrubber::scrubString(...), $frame['pre_context']);
                $frame['post_context'] = array_map(PiiScrubber::scrubString(...), $frame['post_context']);
            }

            return $frame;
        }, $payload['backtrace']);
        $payload['context'] = PiiScrubber::scrub($payload['context']);
        $payload['tags'] = PiiScrubber::scrub($payload['tags']);

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function backtrace(Throwable $throwable): array
    {
        $trace = $throwable->getTrace();
        $frames = [];

        // PHP's own trace format is call-site-shifted: trace[i]'s
        // file/line is *where trace[i]'s function was called from*, not
        // where that function itself executes -- verified directly
        // against a real nested-function throw, not assumed (see the
        // README's note on this). Building a frame list where each
        // entry's file/line actually matches its own method name means
        // pairing frame i's method with frame (i-1)'s file/line, with
        // frame -1 being the throwable's own getFile()/getLine() (i.e.
        // where it was actually thrown).
        $file = $throwable->getFile();
        $line = $throwable->getLine();

        foreach ($trace as $entry) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            $method = $entry['function'] ?? null;
            if ($method !== null && isset($entry['class'])) {
                $method = $entry['class'] . ($entry['type'] ?? '::') . $method;
            }

            $frames[] = $this->attachSourceContext([
                'file' => $file,
                'line' => $line,
                'method' => $method,
                'in_app' => $this->isInApp($file),
            ]);

            $file = $entry['file'] ?? null;
            $line = $entry['line'] ?? null;
        }

        return $frames;
    }

    private function isInApp(?string $file): bool
    {
        if ($file === null || $this->configuration->appRoot === '') {
            return false;
        }

        if (!str_starts_with($file, $this->configuration->appRoot)) {
            return false;
        }

        return !str_contains($file, '/vendor/');
    }

    /**
     * Reads a few lines of source straight off disk around the culprit
     * line, at throw-time, in the same running process the exception came
     * from. Gated on two things: the frame has to be in-app (never a
     * vendored dependency -- there'd be nothing meaningful to show, and
     * it's not the host app's own code to begin with), and
     * Configuration::$captureSourceContext has to be true (see Configuration
     * for why it defaults to true, and why ForgeOps' own per-project
     * setting, not this flag, is the durable, protected way to turn it
     * off). Best-effort: any file that can't be read (deleted, permission
     * denied, a path that only ever existed inside a build step and isn't
     * present in this deployment) just means this one frame gets no source
     * context, never a thrown error of its own.
     *
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    private function attachSourceContext(array $frame): array
    {
        if (!$this->configuration->captureSourceContext || !$frame['in_app']) {
            return $frame;
        }

        $lines = $this->readSourceLines($frame['file']);
        if ($lines === null) {
            return $frame;
        }

        $index = $frame['line'] - 1;
        if ($index < 0 || $index >= count($lines)) {
            return $frame;
        }

        $from = max($index - self::CONTEXT_LINES, 0);
        $to = min($index + self::CONTEXT_LINES, count($lines) - 1);

        $frame['context_line'] = self::truncateLine($lines[$index]);
        $frame['pre_context'] = array_map(self::truncateLine(...), array_slice($lines, $from, $index - $from));
        $frame['post_context'] = array_map(self::truncateLine(...), array_slice($lines, $index + 1, $to - $index));

        return $frame;
    }

    /**
     * Isolated into its own method (rather than inlined into
     * attachSourceContext()) so tests can mock it to prove no read is ever
     * attempted when Configuration::$captureSourceContext is off.
     *
     * @return string[]|null
     */
    protected function readSourceLines(string $file): ?array
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);

        return $lines === false ? null : $lines;
    }

    private static function truncateLine(string $line): string
    {
        if (strlen($line) <= self::MAX_CONTEXT_LINE_LENGTH) {
            return $line;
        }

        return substr($line, 0, self::MAX_CONTEXT_LINE_LENGTH) . '...';
    }
}
