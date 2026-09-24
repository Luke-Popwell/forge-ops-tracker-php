<?php

declare(strict_types=1);

namespace ForgeOps\Tracker;

use Throwable;

/**
 * Ties Configuration, EventBuilder, and DeliveryQueue together into the
 * one thing callers actually need: report an exception. Mirrors
 * gems/forge_ops_tracker's ErrorSubscriber#report: never throws. An
 * error reporter that itself throws while reporting an error is the
 * worst possible failure mode, so every path here is wrapped to
 * guarantee this never propagates back into the host app.
 */
class Reporter
{
    public function __construct(
        private Configuration $configuration,
        private EventBuilder $eventBuilder,
        private DeliveryQueue $deliveryQueue,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $user
     * @param array<int, array<string, mixed>> $breadcrumbs
     */
    public function report(
        Throwable $throwable,
        array $context = [],
        ?array $user = null,
        array $breadcrumbs = [],
        ?string $transactionName = null,
        ?string $endpoint = null,
        ?string $traceId = null,
    ): void {
        try {
            if (!$this->configuration->isEnabled()) {
                return;
            }

            $payload = $this->eventBuilder->build($throwable, $context, $user, $breadcrumbs, $transactionName, $endpoint, $traceId);
            $this->deliveryQueue->push($payload);
        } catch (Throwable $e) {
            $this->configuration->log(
                '[forge-ops-tracker] report failed: ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }
}
