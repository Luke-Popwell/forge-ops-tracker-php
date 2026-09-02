<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Delivers one payload over HTTP. Every failure mode -- DNS, connection,
 * timeout, TLS, a non-2xx response -- is caught here and turned into a
 * `false` return rather than a thrown exception, since a broken or
 * unreachable tracker must never be able to break the host app. Ported
 * from gems/forge_ops_tracker/lib/forge_ops_tracker/client.rb.
 *
 * Uses ext-curl rather than a package dependency (Guzzle, etc.) -- same
 * reason the Ruby gem uses plain Net::HTTP and the Python client uses
 * only urllib: this has to work in any host app without adding an HTTP
 * client dependency of its own. curl is about as close to "always
 * available" as PHP has for this.
 */
class Client
{
    public function __construct(private Configuration $configuration)
    {
    }

    /** @param array<string, mixed> $payload */
    public function deliver(array $payload): bool
    {
        $uri = $this->configuration->ingestionUri();
        if ($uri === null) {
            return false;
        }

        $body = json_encode($payload);
        if ($body === false) {
            $this->configuration->log('[forge-ops-tracker] delivery failed: could not encode payload as JSON');

            return false;
        }

        $ch = curl_init($uri);
        if ($ch === false) {
            return false;
        }

        try {
            $timeoutMs = (int) round($this->configuration->timeout * 1000);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->configuration->apiKey(),
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            ]);

            $result = curl_exec($ch);
            if ($result === false) {
                $this->configuration->log('[forge-ops-tracker] delivery failed: ' . curl_error($ch));

                return false;
            }

            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            $this->configuration->log('[forge-ops-tracker] delivery failed: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }
        // No curl_close($ch) -- deprecated as of PHP 8.5 (verified
        // directly, not assumed): it's been a no-op since PHP 8.0, when
        // curl handles became CurlHandle objects with automatic
        // garbage-collected cleanup rather than a resource type needing
        // an explicit close.
    }
}
