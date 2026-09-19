<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Symfony;

use ForgeOps\Tracker\ForgeOpsTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Autowired/autoconfigured the same way as ForgeOpsTrackerSessionListener (see that class's own
 * doc comment). kernel.request marks the start time; kernel.response (which fires even after an
 * exception, same reasoning ForgeOpsTrackerSessionListener's own comment documents) computes the
 * elapsed time and reports it. Sub-requests are skipped, same reasoning again: only the main
 * request corresponds to a real timed transaction.
 *
 * transactionName is the request's own "_route" attribute (Symfony's own route *name*, e.g.
 * "user_detail", set by the router once a route actually matches) rather than the raw path,
 * which keeps a distinct user id from exploding into its own separate transaction the way the
 * literal path would. Falls back to the path info if no route matched at all (a 404).
 *
 * Also opens a trace for the request and finishes it here, root span named like the transaction;
 * with no Doctrine/DBAL hook in this integration the only automatic span is the request itself,
 * so add the rest by hand with ForgeOpsTracker::span().
 *
 * Also records a breadcrumb alongside this one performance sample, gated on trackBreadcrumbs
 * independently of trackPerformance (see ForgeOpsTracker::recordBreadcrumb()'s own doc comment
 * for where that flag check actually lives). No query-level breadcrumb here the way Laravel's own
 * equivalent middleware has one: this SDK's Symfony integration has no database query timing
 * source of its own to begin with (no Doctrine/DBAL listener exists in this integration), and
 * breadcrumbs deliberately only cover sources performance monitoring already instruments, not a
 * new one invented just for this feature.
 */
final class ForgeOpsTrackerPerformanceListener implements EventSubscriberInterface
{
    private const START_ATTRIBUTE = '_forge_ops_tracker_performance_start';

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

        $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
        ForgeOpsTracker::startTrace();
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $start = $request->attributes->get(self::START_ATTRIBUTE);
        if ($start === null) {
            return;
        }

        $durationMs = (microtime(true) - $start) * 1000;
        $route = $request->attributes->get('_route') ?? $request->getPathInfo();
        $transactionName = $request->getMethod() . ' ' . $route;
        ForgeOpsTracker::recordPerformance($transactionName, $durationMs);

        $status = $event->getResponse()->getStatusCode();
        ForgeOpsTracker::recordBreadcrumb(
            'controller',
            $transactionName,
            $status >= 500 ? 'error' : 'info',
            ['status' => $status, 'path' => $request->getPathInfo()],
        );

        ForgeOpsTracker::finishTrace($transactionName, $start, $durationMs);
    }
}
