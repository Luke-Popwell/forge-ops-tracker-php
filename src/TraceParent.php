<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Reads and writes the W3C Trace Context `traceparent` header (https://www.w3.org/TR/trace-context/),
 * the vendor-neutral format for carrying one trace across service boundaries:
 * `00-<32 hex trace-id>-<16 hex parent-id>-<2 hex flags>`. Used in both directions: the Laravel
 * middleware and Symfony listener parse an incoming one so the request continues the caller's trace
 * instead of starting its own, and ForgeOpsTracker::httpSpan() builds an outgoing one so the next
 * service along continues this request's. Ported from gems/forge_ops_tracker's trace_parent.rb.
 *
 * Deliberately strict on the way in, the same posture the spec asks receivers to take: a malformed
 * value, uppercase hex, the reserved version "ff", or an all-zero trace/parent id are all treated as
 * "no usable header at all" (parse() returns null and the request starts a fresh trace). A version
 * this client doesn't know yet is still accepted as long as its first four fields have version 00's
 * shape, which is what the spec says a version-00 parser should do with a future version; version
 * 00 itself must have exactly four fields.
 */
final class TraceParent
{
    public const HEADER = 'traceparent';

    /**
     * Always "01" (sampled) on the way out: whether a trace is actually sent is only decided once the
     * request is over, long after this header has gone out on an outbound call, so there's no honest
     * earlier answer than "this may be recorded."
     */
    private const SAMPLED_FLAGS = '01';

    private const PATTERN = '/\A(?<version>[0-9a-f]{2})-(?<trace_id>[0-9a-f]{32})-(?<parent_id>[0-9a-f]{16})-(?<flags>[0-9a-f]{2})(?<rest>-.*)?\z/s';

    /**
     * ['trace_id' => ..., 'parent_span_id' => ...] for a usable header, null for anything else
     * (absent, blank, malformed, or one of the explicitly invalid values above).
     *
     * @return array{trace_id: string, parent_span_id: string}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match(self::PATTERN, trim($value), $match) !== 1) {
            return null;
        }
        if ($match['version'] === 'ff') {
            return null;
        }
        if ($match['version'] === '00' && ($match['rest'] ?? '') !== '') {
            return null;
        }
        if ($match['trace_id'] === str_repeat('0', 32) || $match['parent_id'] === str_repeat('0', 16)) {
            return null;
        }

        return ['trace_id' => $match['trace_id'], 'parent_span_id' => $match['parent_id']];
    }

    public static function build(string $traceId, string $spanId): string
    {
        return '00-' . $traceId . '-' . $spanId . '-' . self::SAMPLED_FLAGS;
    }

    /** 32 lowercase hex characters, the W3C trace-id format; never all zeros. */
    public static function generateTraceId(): string
    {
        return self::randomHex(16);
    }

    /** 16 lowercase hex characters, the W3C parent-id (span id) format; never all zeros. */
    public static function generateSpanId(): string
    {
        return self::randomHex(8);
    }

    private static function randomHex(int $bytes): string
    {
        do {
            $hex = bin2hex(random_bytes($bytes));
        } while (trim($hex, '0') === '');

        return $hex;
    }
}
