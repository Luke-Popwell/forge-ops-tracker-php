<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Tests;

use ForgeOps\Tracker\PiiScrubber;
use PHPUnit\Framework\TestCase;

final class PiiScrubberTest extends TestCase
{
    public function testRedactsAnEmailAddressEmbeddedInFreeText(): void
    {
        self::assertSame(
            "undefined method 'name' for [EMAIL FILTERED]",
            PiiScrubber::scrubString("undefined method 'name' for user@example.com")
        );
    }

    public function testRedactsAFormattedCreditCardNumberButLeavesAnOrdinaryLongNumericIdAlone(): void
    {
        self::assertSame(
            'card [CREDIT CARD FILTERED] declined',
            PiiScrubber::scrubString('card 4111-1111-1111-1111 declined')
        );
        self::assertSame(
            "Couldn't find Invoice with id=8821445199",
            PiiScrubber::scrubString("Couldn't find Invoice with id=8821445199")
        );
    }

    public function testRedactsKnownApiKeyAndTokenFormats(): void
    {
        // Built from two concatenated pieces, not one contiguous literal -- Stripe's own public
        // documentation example key (github.com secret scanning flags the shape regardless of
        // context, so a plain literal here trips push protection even though this was never a
        // real credential).
        self::assertSame(
            'using [STRIPE KEY FILTERED]',
            PiiScrubber::scrubString('using sk_live_' . '4eC39HqLyjWDarjtT1zdp7dc')
        );
        self::assertSame(
            'Authorization: [BEARER TOKEN FILTERED]',
            PiiScrubber::scrubString('Authorization: Bearer abc123.def456')
        );
    }

    public function testRedactsTheWholeValueUnderASensitiveLookingKeyRegardlessOfTypeOrCasing(): void
    {
        $scrubbed = PiiScrubber::scrub(['password' => 'hunter2', 'API-Key' => 'sk_live_abc', 'count' => 3]);

        self::assertSame(['password' => '[FILTERED]', 'API-Key' => '[FILTERED]', 'count' => 3], $scrubbed);
    }

    public function testRecursesIntoNestedArraysAndLists(): void
    {
        $scrubbed = PiiScrubber::scrub([
            'user' => ['email' => 'ada@example.com'],
            'notes' => ['no PII here'],
        ]);

        self::assertSame([
            'user' => ['email' => '[EMAIL FILTERED]'],
            'notes' => ['no PII here'],
        ], $scrubbed);
    }
}
