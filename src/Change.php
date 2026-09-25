<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Builds the body for one ForgeOpsTracker::recordChange() call (POST /api/v1/changes): something
 * that changed in a running system (a feature flag flipped, a config value edited, a migration run
 * by hand), so ForgeOps can line it up against the errors and slowdowns around it. Mirrors
 * gems/forge_ops_tracker's Change.
 */
final class Change
{
    /**
     * The server rejects any other kind outright (422), so an unknown one is sent as "other" rather
     * than dropped: the change still gets recorded, just less specifically categorized.
     */
    public const KINDS = ['feature_flag', 'config', 'migration', 'dependency', 'infrastructure', 'other'];

    private const MAX_TITLE_LENGTH = 200;

    public static function normalizeKind(string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? $kind : 'other';
    }

    /**
     * Returns null when there's nothing sendable (a blank title: the server requires one, so
     * posting it anyway would only ever come back 422).
     *
     * @param array<mixed> $details
     * @return array<string, mixed>|null
     */
    public static function build(
        Configuration $configuration,
        string $kind,
        string $title,
        array $details = [],
        ?string $environment = null,
        ?string $service = null,
        ?string $actor = null,
        ?string $url = null,
        ?string $id = null,
        \DateTimeInterface|string|null $occurredAt = null,
    ): ?array {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        $payload = [
            'kind' => self::normalizeKind($kind),
            'title' => self::truncate($title, self::MAX_TITLE_LENGTH),
            // An empty (or list-shaped) PHP array would encode as a JSON array, and the server wants
            // an object here.
            'details' => $details === [] || array_is_list($details) ? new \stdClass() : $details,
            'environment' => $environment ?? $configuration->environment,
            'service' => $service,
            'actor' => $actor,
            'url' => $url,
            'id' => $id,
            'occurred_at' => self::iso8601($occurredAt),
        ];

        return array_filter($payload, static fn ($value) => $value !== null);
    }

    /**
     * The first $length characters, never splitting a multibyte one (a half character would make the
     * whole payload fail to encode as JSON). PCRE rather than mb_substr(), since this library
     * doesn't require ext-mbstring; falls back to bytes for a string that isn't valid UTF-8.
     */
    public static function truncate(string $value, int $length): string
    {
        if (preg_match('/\A.{0,' . $length . '}/us', $value, $matches) === 1) {
            return $matches[0];
        }

        return substr($value, 0, $length);
    }

    private static function iso8601(\DateTimeInterface|string|null $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        $value ??= new \DateTimeImmutable();

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
