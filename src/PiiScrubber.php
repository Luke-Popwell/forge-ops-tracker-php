<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Redacts likely-sensitive content out of a payload before it ever leaves
 * this process: the same patterns ForgeOps itself applies again on
 * arrival (defense in depth: this layer keeps the data off the wire and
 * out of any request logging in between; the server-side layer is what
 * actually protects the database, and doesn't depend on every reporting
 * app running an up-to-date version of this client). Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/pii_scrubber.rb: kept
 * standalone and dependency-free here for the same reason as the Ruby
 * original: this has to work in any host app regardless of what's
 * reporting into it.
 *
 * Can be turned off via Configuration::$scrubPii = false for a host app
 * that already scrubs its own data before it ever reaches exception
 * context, or that has its own reasons to want the raw payload. Off by
 * default is not an option: the safe default has to be "on."
 */
final class PiiScrubber
{
    public const REDACTED = '[FILTERED]';

    /** @var string[] */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd',
        'secret', 'apisecret', 'clientsecret', 'secretkey',
        'token', 'accesstoken', 'refreshtoken', 'apikey', 'apitoken', 'authorization', 'authtoken', 'bearer',
        'sessiontoken', 'csrftoken',
        'creditcard', 'cardnumber', 'cardnum', 'cvv', 'cvv2', 'cvc',
        'iban', 'accountnumber', 'routingnumber', 'bankaccount',
        'ssn', 'socialsecuritynumber', 'socialsecurity',
        'privatekey',
    ];

    /** @var array<string, string> label => PCRE pattern */
    private const PATTERNS = [
        'EMAIL' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        'SSN' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'CREDIT CARD' => '/\b\d{4}[ -]\d{4}[ -]\d{4}[ -]\d{1,4}\b/',
        'BEARER TOKEN' => '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
        'JWT' => '/\bey[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        'AWS KEY' => '/\bAKIA[0-9A-Z]{16}\b/',
        'STRIPE KEY' => '/\b[sr]k_(?:live|test)_[A-Za-z0-9]{10,}\b/',
        'GITHUB TOKEN' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
    ];

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function scrub($value, ?string $key = null)
    {
        if (self::isSensitiveKey($key) && $value !== null) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            $scrubbed = [];
            foreach ($value as $k => $v) {
                // Preserve string keys (associative/"hash" shape);
                // sequential numeric keys (a plain "list") are rebuilt
                // positionally so array_is_list() output stays a list.
                $scrubbed[is_string($k) ? $k : count($scrubbed)] = self::scrub($v, is_string($k) ? $k : $key);
            }

            return $scrubbed;
        }

        if (is_string($value)) {
            return self::scrubString($value);
        }

        return $value;
    }

    /*
     * Card numbers in any other shape, and IBANs: see sdks/PII_SCRUBBING.md, which every SDK
     * follows, and sdks/pii_scrubbing_vectors.json (a copy is in tests/fixtures), which pins the
     * results. Word characters and digits are spelled out as ASCII classes rather than \b, \w or
     * \d, so the rule stays ASCII only whatever modifiers or locale PCRE runs with.
     */
    private const DIGIT_RUN = '/(?<![A-Za-z0-9_])[0-9](?:[ -]?[0-9])*(?![A-Za-z0-9_])/';
    private const CARD_PREFIXES = '/\A(?:4|5[1-5]|2(?:22[1-9]|2[3-9][0-9]|[3-6][0-9][0-9]|7[01][0-9]|720)|3[47]|6011|64[4-9]|65|35(?:2[89]|[3-8][0-9])|36|30[0-5]|62)/';
    private const IBAN = '/(?<![A-Za-z0-9_])[A-Z]{2}[0-9]{2}(?:[A-Z0-9]{11,30}|(?: [A-Z0-9]{4}){2,7}(?: [A-Z0-9]{1,4})?)(?![A-Za-z0-9_])/';

    public static function scrubString(string $text): string
    {
        // IBANs first: a spaced IBAN's middle groups look like a formatted card number.
        $text = preg_replace_callback(
            self::IBAN,
            static fn (array $match): string => self::isIban($match[0]) ? '[IBAN FILTERED]' : $match[0],
            $text
        ) ?? $text;

        foreach (self::PATTERNS as $label => $pattern) {
            $text = preg_replace($pattern, "[$label FILTERED]", $text) ?? $text;
        }

        return preg_replace_callback(
            self::DIGIT_RUN,
            static fn (array $match): string => self::scrubCardNumbersIn($match[0]),
            $text
        ) ?? $text;
    }

    /**
     * From each group of the run on, the longest stretch of whole groups that is a card number is
     * replaced, so a card followed by its CVV, two cards in a row, and Amex's 4-6-5 are all found.
     */
    private static function scrubCardNumbersIn(string $run): string
    {
        $parts = preg_split('/([ -])/', $run, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$run];
        $digits = [];
        $separators = [];
        for ($index = 0; $index < count($parts); $index += 2) {
            $digits[] = $parts[$index];
            $separators[] = $parts[$index + 1] ?? '';
        }

        $out = '';
        $i = 0;
        $count = count($digits);
        while ($i < $count) {
            $found = null;
            for ($last = $count - 1; $last >= $i; $last -= 1) {
                if (self::isCardNumber(implode('', array_slice($digits, $i, $last - $i + 1)))) {
                    $found = $last;
                    break;
                }
            }
            if ($found !== null) {
                $out .= '[CREDIT CARD FILTERED]' . $separators[$found];
                $i = $found + 1;
            } else {
                $out .= $digits[$i] . $separators[$i];
                $i++;
            }
        }

        return $out;
    }

    private static function isCardNumber(string $digits): bool
    {
        $length = strlen($digits);

        return $length >= 13 && $length <= 19 && preg_match(self::CARD_PREFIXES, $digits) === 1 && self::passesLuhn($digits);
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $reversed = strrev($digits);
        for ($index = 0; $index < strlen($reversed); $index++) {
            $value = ord($reversed[$index]) - ord('0');
            if ($index % 2 === 1) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return $sum % 10 === 0;
    }

    /**
     * The IBAN check, a digit at a time: the first four characters moved to the end, letters as
     * two digits (A is 10), and the remainder by 97 is 1.
     */
    private static function isIban(string $candidate): bool
    {
        $compact = str_replace(' ', '', $candidate);
        $length = strlen($compact);
        if ($length < 15 || $length > 34) {
            return false;
        }

        $rearranged = substr($compact, 4) . substr($compact, 0, 4);
        $remainder = 0;
        for ($index = 0; $index < strlen($rearranged); $index++) {
            $char = $rearranged[$index];
            $number = ($char >= 'A' && $char <= 'Z') ? (string) (ord($char) - 55) : $char;
            for ($digit = 0; $digit < strlen($number); $digit++) {
                $remainder = ($remainder * 10 + ord($number[$digit]) - ord('0')) % 97;
            }
        }

        return $remainder === 1;
    }

    private static function isSensitiveKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
