<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Buckets a single duration into one of a fixed set of latency-range labels, the building block
 * PerformanceFlusher uses to send an approximate distribution (not just count/sum/max) with every
 * performance sample. The server merges these counts across matching samples at read time and walks
 * cumulative counts to approximate a percentile, accurate to the bucket width: this SDK never
 * stores the raw duration list a true percentile would need. Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/histogram_bucketer.rb.
 *
 * BOUNDARIES_MS is duplicated on the server side, in app/services/histogram_percentile.rb. Change
 * one, change the other, or a released SDK version and the server it talks to would silently
 * disagree about what each bucket label means.
 */
final class HistogramBucketer
{
    public const BOUNDARIES_MS = [50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /**
     * Returns the label of the smallest boundary $durationMs fits under, or "inf" for anything
     * larger than the largest boundary. A string, not an int: this travels as a JSON object key
     * once flushed, and JSON object keys are always strings.
     */
    public static function bucketFor(float $durationMs): string
    {
        foreach (self::BOUNDARIES_MS as $boundary) {
            if ($durationMs <= $boundary) {
                return (string) $boundary;
            }
        }

        return 'inf';
    }
}
