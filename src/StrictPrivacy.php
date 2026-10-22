<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * What Configuration::$strictPrivacy changes in everything this client sends, for apps that can't
 * let personal or health data leave their own servers. With it on, only structured facts go out
 * (the error's class, its stack frames, release, environment, server, which request it happened
 * in, timings and counts); free text, which is where a name, an email or a diagnosis hides, never
 * leaves this process: no error message, no breadcrumbs, no endpoint, no SQL statement, no source
 * context, no user email or username, and only the context/tag keys listed in
 * Configuration::$privacyAllowedFields.
 *
 * The same rules as the server's own StrictPrivacy (app/services/strict_privacy.rb), which applies
 * them again on arrival when the project's Strict privacy setting is on;
 * sdks/strict_privacy_vectors.json pins the results both have to agree on, byte for byte.
 */
final class StrictPrivacy
{
    public const FINGERPRINT_LENGTH = 32;

    // ASCII "word" characters; boundaries are defined against these, the same in every SDK. No /u
    // modifier anywhere: the patterns run over bytes, so every byte of a multibyte UTF-8 character
    // counts as a non-word character, exactly what the spec asks for, and a message that isn't
    // valid UTF-8 can't make a pattern fail.
    private const WORD = 'A-Za-z0-9_';
    private const EMAIL = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    // A quoted value (KeyError: 'Jane Doe') is data, not the shape of the error. A single quote only
    // opens or closes one where no ASCII word character touches it, so "Couldn't" never does.
    private const DOUBLE_QUOTED = '/"[^"\r\n]*"/';
    private const SINGLE_QUOTED = '/(?<![' . self::WORD . '])\'[^\'\r\n]*\'(?![' . self::WORD . '])/';
    private const HEX_OBJECT_ID = '/0x[0-9a-fA-F]{4,}/';
    private const UUID = '/(?<![' . self::WORD . '])[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?![' . self::WORD . '])/';
    private const NUMBER = '/(?<![' . self::WORD . '])[0-9]+(?![' . self::WORD . '])/';

    /** Span data that's structure, not content: the masked statement and which database it ran on. */
    public const SPAN_DATA_KEYS = ['db.statement', 'db.system'];
    private const HTTP_METHOD = '/\A(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)/i';
    private const SCHEME = '~\A[a-z][a-z0-9+.-]*://~i';
    private const ROUTE_PARAMETER = '/\A(?::.+|\{.+\}|<.+>|\[.+\]|\*.*)\z/';

    /** Error event fields that are never sent, and the per-frame source context. */
    private const EVENT_FIELDS_DROPPED = ['message', 'breadcrumbs', 'endpoint', 'sql_statement'];
    private const SOURCE_CONTEXT_FIELDS = ['context_line', 'pre_context', 'post_context'];

    /**
     * The message with emails, quoted values, hex object ids, UUIDs and standalone numbers replaced,
     * in that order.
     */
    public static function normalizeMessage(?string $message): string
    {
        $message = preg_replace(self::EMAIL, '<email>', $message ?? '');
        $message = preg_replace(self::DOUBLE_QUOTED, '<str>', $message);
        $message = preg_replace(self::SINGLE_QUOTED, '<str>', $message);
        $message = preg_replace(self::HEX_OBJECT_ID, '0x', $message);
        $message = preg_replace(self::UUID, '<uuid>', $message);

        return preg_replace(self::NUMBER, '<n>', $message);
    }

    /**
     * Lets ForgeOps group errors without ever seeing their message: the first 32 hex characters of
     * the SHA-256 of the normalized message's UTF-8 bytes, so two messages that differ only in ids
     * hash the same, and nothing about the text can be read back from it. PHP strings are already
     * bytes, UTF-8 in any app that sends JSON.
     */
    public static function messageFingerprint(?string $message): string
    {
        return substr(hash('sha256', self::normalizeMessage($message)), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * An error event payload (EventBuilder::build()'s shape) reduced to what strict privacy sends.
     * message is sent as "" alongside message_fingerprint, exactly what the server stores for a
     * strict project.
     *
     * @param array<string, mixed> $payload
     * @param string[] $allowedFields
     * @return array<string, mixed>
     */
    public static function event(array $payload, array $allowedFields = []): array
    {
        $reduced = array_diff_key($payload, array_flip(self::EVENT_FIELDS_DROPPED));
        $reduced['message'] = '';
        $reduced['message_fingerprint'] = self::messageFingerprint(is_string($payload['message'] ?? null) ? $payload['message'] : null);
        if (array_key_exists('transaction_name', $payload)) {
            $reduced['transaction_name'] = self::transactionName($payload['transaction_name']);
        }
        $reduced['context'] = self::allowed($payload['context'] ?? null, $allowedFields);
        $reduced['tags'] = self::allowed($payload['tags'] ?? null, $allowedFields);
        $reduced['user'] = self::user($payload['user'] ?? null);
        $reduced['backtrace'] = array_map(
            static fn (array $frame): array => array_diff_key($frame, array_flip(self::SOURCE_CONTEXT_FIELDS)),
            $payload['backtrace'] ?? [],
        );

        return array_filter($reduced, static fn ($value) => $value !== null);
    }

    /**
     * A trace's span: an outbound call's name keeps its method and host, never its path or query,
     * and its data keeps only the masked statement and database system. data is left out entirely
     * when nothing is left of it, the same as the server does.
     *
     * @param array<string, mixed> $span
     * @return array<string, mixed>
     */
    public static function span(array $span): array
    {
        $span['name'] = self::spanName($span['name'] ?? null);
        // SpanBuffer keeps data as an object, so an empty one still encodes as {}; kept that way.
        $original = $span['data'] ?? null;
        $data = array_intersect_key(is_array($original) || is_object($original) ? (array) $original : [], array_flip(self::SPAN_DATA_KEYS));
        if ($data === []) {
            unset($span['data']);
        } else {
            $span['data'] = is_object($original) ? (object) $data : $data;
        }

        return array_filter($span, static fn ($value) => $value !== null);
    }

    /**
     * "GET https://api.example.com/patients/42?x=1" or "GET api.example.com/patients/42" becomes
     * "GET api.example.com". A bare path ("/patients/42"), a method and a path ("PUT
     * /orders/17/items"), or a request named by its path ("GET patients/42", Laravel's own form for
     * a request no route matched), goes through the path rule, like a page path. A name that's none
     * of these ("OrdersController#create", "patients#show") is kept.
     */
    public static function spanName(?string $name): ?string
    {
        if ($name !== null && str_starts_with($name, '/')) {
            return self::strictPath($name);
        }
        if ($name === null || preg_match(self::HTTP_METHOD, $name, $match) !== 1) {
            return $name;
        }

        $method = strtoupper($match[1]);
        $target = $match[2];
        if (str_starts_with($target, '/')) {
            return $method . ' ' . self::strictPath($target);
        }

        return $method . ' ' . (self::host($target) ?? self::strictPath($target));
    }

    /**
     * Which request something happened in: a route or a controller action is kept, but a raw path
     * (what a request no route matched falls back to) keeps only its first segment, by the span
     * name rule.
     */
    public static function transactionName(?string $name): ?string
    {
        return $name === null || $name === '' ? $name : self::spanName($name);
    }

    /**
     * A request or page path under strict privacy, with no query or fragment. A route template
     * ("/patients/:id/notes", "/patients/{id}", "users/<int:id>/", "/files/*path", "/posts/[slug]")
     * is kept as it is: its words are a developer's, and its parameters are already placeholders. A
     * raw path keeps only its first segment, and the rest becomes ":path": "/patients/jane-doe/12"
     * is "/patients/:path". Anything after the first segment of a raw path could name a person, and
     * no rule can tell a name from a word ("/patients/janedoe"). A first segment that holds a digit
     * or is over 40 characters is ":id" itself.
     */
    public static function strictPath(?string $path): string
    {
        $path = preg_split('/[?#]/', $path ?? '', 2)[0];
        if (self::isRouteTemplate($path)) {
            return $path;
        }

        $leading = str_starts_with($path, '/') ? '/' : '';
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            return '/';
        }

        $first = preg_match('/[0-9]/', $segments[0]) === 1 || self::length($segments[0]) > 40 ? ':id' : $segments[0];

        return $leading . $first . (count($segments) > 1 ? '/:path' : '');
    }

    // Whether any segment is a route parameter: ":id", "{id}", "<int:id>", "[slug]" or "*path".
    private static function isRouteTemplate(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if (preg_match(self::ROUTE_PARAMETER, $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    // Characters, not bytes, like the server's String#length, without needing ext-mbstring; bytes
    // only for a segment that isn't valid UTF-8.
    private static function length(string $segment): int
    {
        $count = preg_match_all('/./su', $segment);

        return $count === false ? strlen($segment) : $count;
    }

    // An outbound call's host, read by hand rather than with parse_url() (an address a parser
    // rejects must never be sent whole): what comes before the first "/", "?" or "#", without a
    // scheme, credentials (up to the last "@", since a password can hold one) or port. Only a host
    // when the address had a scheme or a port, or the name has a dot or is "localhost"; null
    // otherwise (a relative path).
    private static function host(string $target): ?string
    {
        $scheme = preg_match(self::SCHEME, $target) === 1;
        $authority = preg_split('~[/?#]~', (string) preg_replace(self::SCHEME, '', $target), 2)[0];
        $authority = (string) preg_replace('/\A.*@/s', '', $authority, 1);
        $port = preg_match('/:[0-9]+\z/', $authority) === 1;
        $host = (string) preg_replace('/:[0-9]+\z/', '', $authority);
        if ($host === '') {
            return null;
        }

        return $scheme || $port || str_contains($host, '.') || strtolower($host) === 'localhost' ? $host : null;
    }

    /**
     * Only top-level keys named in $allowedFields, and only plain values under them; null (left out
     * of the payload) when nothing is left.
     *
     * @param string[] $allowedFields
     * @return array<string, mixed>|null
     */
    private static function allowed(mixed $fields, array $allowedFields): ?array
    {
        $allowedFields = array_map('strval', $allowedFields);
        if ($allowedFields === [] || !is_array($fields)) {
            return null;
        }

        $kept = [];
        foreach ($fields as $key => $value) {
            if (in_array((string) $key, $allowedFields, true) && (is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                $kept[(string) $key] = $value;
            }
        }

        return $kept === [] ? null : $kept;
    }

    /**
     * The user's id, as a string, and nothing else; null when there's no id.
     *
     * @return array{id: string}|null
     */
    private static function user(mixed $user): ?array
    {
        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if ($id === null || is_array($id) || trim((string) $id) === '') {
            return null;
        }

        return ['id' => (string) $id];
    }
}
