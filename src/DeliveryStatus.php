<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * What became of one delivery attempt. A bare true/false could not tell "the server will never
 * take this payload" apart from "try again later", so a batch a plan or a validation rule refused
 * was re-sent at every flush for as long as the process lived. Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/delivery_status.rb.
 *
 * Client's own deliver*() methods still answer a bool (true only for DELIVERED); the status of the
 * attempt behind that bool is Client::lastStatus().
 */
final class DeliveryStatus
{
    /** Any 2xx. */
    public const DELIVERED = 'delivered';

    /** A 4xx other than 408 and 429: sending the same payload again can only fail the same way. */
    public const REJECTED = 'rejected';

    /** A network error, a timeout, a 5xx, a 408 or a 429: worth another attempt later. */
    public const FAILED = 'failed';

    /**
     * After this many flushes in a row that failed for now, a batch is dropped rather than kept
     * for yet another attempt, so an outage can never pin the same data in a long-running worker's
     * memory for the life of the process.
     */
    public const MAX_FAILED_FLUSHES = 5;

    public static function forCode(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return self::DELIVERED;
        }
        if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
            return self::REJECTED;
        }

        return self::FAILED;
    }

    /**
     * Reads whatever a deliver callable handed back. A status passes through; anything else is
     * read the way the old boolean contract was (truthy delivered, falsy failed for now), so a
     * callable that still answers true/false keeps working.
     */
    public static function from(mixed $result): string
    {
        if ($result === self::DELIVERED || $result === self::REJECTED || $result === self::FAILED) {
            return $result;
        }

        return $result ? self::DELIVERED : self::FAILED;
    }
}
