<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

/**
 * Delivers one payload over HTTP, either an error event or a session
 * checkin, sharing one post() implementation. Every failure mode: DNS,
 * connection, timeout, TLS, a non-2xx response, is caught here and turned
 * into a `false` return rather than a thrown exception, since a broken or
 * unreachable tracker must never be able to break the host app. Ported
 * from gems/forge_ops_tracker/lib/forge_ops_tracker/client.rb.
 *
 * Every deliver*() method answers true only when the payload was
 * delivered. Whether a false is worth another attempt is lastStatus():
 * see DeliveryStatus.
 *
 * Uses ext-curl rather than a package dependency (Guzzle, etc.): same
 * reason the Ruby gem uses plain Net::HTTP and the Python client uses
 * only urllib: this has to work in any host app without adding an HTTP
 * client dependency of its own. curl is about as close to "always
 * available" as PHP has for this.
 */
class Client
{
    private string $lastStatus = DeliveryStatus::FAILED;

    public function __construct(private Configuration $configuration)
    {
    }

    /**
     * The DeliveryStatus of the most recent deliver*() call on this client: what tells a caller
     * holding a false whether the server rejected the payload for good or delivery only failed
     * for now. One request runs at a time in a PHP process, so "most recent" is never ambiguous.
     */
    public function lastStatus(): string
    {
        return $this->lastStatus;
    }

    /** @param array<string, mixed> $payload */
    public function deliver(array $payload): bool
    {
        return $this->post($this->configuration->ingestionUri(), $payload) === DeliveryStatus::DELIVERED;
    }

    /** @param array<string, mixed> $payload */
    public function deliverSessionCheckin(array $payload): bool
    {
        return $this->post($this->configuration->sessionCheckinsUri(), $payload) === DeliveryStatus::DELIVERED;
    }

    /** @param array<int, array<string, mixed>> $samples */
    public function deliverPerformanceSamples(array $samples): bool
    {
        return $this->post($this->configuration->performanceSamplesUri(), ['samples' => $samples]) === DeliveryStatus::DELIVERED;
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function deliverMetrics(array $entries): bool
    {
        return $this->post($this->configuration->customMetricsUri(), ['metrics' => $entries]) === DeliveryStatus::DELIVERED;
    }

    /** @param array<int, array<string, mixed>> $entries */
    public function deliverInfrastructureMetrics(array $entries): bool
    {
        return $this->post($this->configuration->infrastructureMetricsUri(), ['metrics' => $entries]) === DeliveryStatus::DELIVERED;
    }

    /** @param array<string, mixed> $trace `{"trace_id": ..., "spans": [...]}` */
    public function deliverSpans(array $trace): bool
    {
        return $this->post($this->configuration->spansUri(), $trace) === DeliveryStatus::DELIVERED;
    }

    /**
     * One ForgeOpsTracker::recordChange() call. A plan without change tracking answers 403, which
     * is just a false here like any other non-2xx (with a lastStatus() of REJECTED).
     *
     * @param array<string, mixed> $payload
     */
    public function deliverChange(array $payload): bool
    {
        return $this->post($this->configuration->changesUri(), $payload) === DeliveryStatus::DELIVERED;
    }

    /** @param array<string, mixed> $payload see ChangeSnapshot::payload() */
    public function deliverChangeSnapshot(array $payload): bool
    {
        return $this->post($this->configuration->changeSnapshotsUri(), $payload) === DeliveryStatus::DELIVERED;
    }

    /**
     * One EXPLAIN plan for a slow query (see QueryPlanner). A project without performance
     * monitoring answers 403, which is just a false here like any other non-2xx.
     *
     * @param array<string, mixed> $payload see QueryPlans::payload()
     */
    public function deliverQueryPlan(array $payload): bool
    {
        return $this->post($this->configuration->queryPlansUri(), $payload) === DeliveryStatus::DELIVERED;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string one of the DeliveryStatus constants, also kept for lastStatus()
     */
    private function post(?string $uri, array $payload): string
    {
        return $this->lastStatus = $this->attempt($uri, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function attempt(?string $uri, array $payload): string
    {
        if ($uri === null) {
            return DeliveryStatus::FAILED;
        }

        $body = json_encode($payload);
        if ($body === false) {
            $this->configuration->log('[forge-ops-tracker] delivery failed: could not encode payload as JSON');

            // The same payload can never encode on a later attempt either.
            return DeliveryStatus::REJECTED;
        }

        $ch = curl_init($uri);
        if ($ch === false) {
            return DeliveryStatus::FAILED;
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

                return DeliveryStatus::FAILED;
            }

            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $status = DeliveryStatus::forCode($code);
            if ($status === DeliveryStatus::REJECTED) {
                $this->configuration->log('[forge-ops-tracker] delivery rejected: HTTP ' . $code . ', the server will not accept a retry');
            } elseif ($status === DeliveryStatus::FAILED) {
                $this->configuration->log('[forge-ops-tracker] delivery failed: HTTP ' . $code);
            }

            return $status;
        } catch (\Throwable $e) {
            $this->configuration->log('[forge-ops-tracker] delivery failed: ' . get_class($e) . ': ' . $e->getMessage());

            return DeliveryStatus::FAILED;
        }
        // No curl_close($ch): deprecated as of PHP 8.5 (verified
        // directly, not assumed): it's been a no-op since PHP 8.0, when
        // curl handles became CurlHandle objects with automatic
        // garbage-collected cleanup rather than a resource type needing
        // an explicit close.
    }
}
