<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Redacts likely-sensitive content out of a payload before it ever leaves
 * this process -- the same patterns ForgeOps itself applies again on
 * arrival (defense in depth: this layer keeps the data off the wire and
 * out of any request logging in between; the server-side layer is what
 * actually protects the database, and doesn't depend on every reporting
 * app running an up-to-date version of this client). Ported from
 * gems/forge_ops_tracker/lib/forge_ops_tracker/pii_scrubber.rb -- kept
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

    public static function scrubString(string $text): string
    {
        foreach (self::PATTERNS as $label => $pattern) {
            $text = preg_replace($pattern, "[$label FILTERED]", $text) ?? $text;
        }

        return $text;
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
