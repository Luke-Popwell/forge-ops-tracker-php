<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Symfony;

use ForgeOps\Tracker\ForgeOpsTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Autowired/autoconfigured the same way as ForgeOpsTrackerSessionListener/
 * ForgeOpsTrackerPerformanceListener (see either class's own doc comment). kernel.request starts a
 * fresh trail; kernel.response clears it. kernel.response, not kernel.terminate: this only needs
 * to outlive kernel.exception (where ForgeOpsTrackerExceptionListener actually reports an error
 * and reads this trail back), and kernel.response is the same "end of request" point
 * ForgeOpsTrackerSessionListener/ForgeOpsTrackerPerformanceListener already use for their own
 * cleanup, so there's no reason for this to be the one listener that waits later. Sub-requests
 * (ESI includes, forwards) are skipped, same reasoning those two listeners already give: only the
 * main request corresponds to a real trail worth tracking.
 *
 * Skips starting a trail at all when trackBreadcrumbs is off (or the client isn't enabled),
 * matching ForgeOpsTrackerBreadcrumbMiddleware's own short-circuit (see that class's own doc
 * comment, including why the manual ForgeOpsTracker::addBreadcrumb() API still works regardless).
 *
 * The kernel.response clear matters even under plain PHP-FPM's shared-nothing-per-request model,
 * not just Laravel Octane (Symfony has no long-running-worker mode of its own documented in this
 * SDK the way Laravel Octane is), but it costs nothing to make unconditional here too, the same
 * "cheap regardless of deployment model" call ForgeOpsTrackerUserContextMiddleware already makes.
 */
final class ForgeOpsTrackerBreadcrumbListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $configuration = ForgeOpsTracker::configuration();
        if (!$configuration->trackBreadcrumbs || !$configuration->isEnabled()) {
            return;
        }

        ForgeOpsTracker::startBreadcrumbTrail();
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        ForgeOpsTracker::endBreadcrumbTrail();
    }
}
