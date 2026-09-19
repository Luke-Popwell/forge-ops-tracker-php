<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Symfony;

use ForgeOps\Tracker\ForgeOpsTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Autowired/autoconfigured automatically if registered as a service (Symfony's default for
 * services under most skeleton config), or add explicitly with the `kernel.event_subscriber` tag.
 *
 * Unlike Laravel's single $next closure, no one Symfony kernel event wraps a whole request, so
 * three hooks stand in for it: kernel.exception marks a request attribute when an exception
 * actually escaped the controller, and kernel.response reads that attribute (falling back to the
 * response's own status code, for a 5xx some other listener produced without ever throwing) to
 * record the session once the request is otherwise done. kernel.response fires even after an
 * exception, since Symfony's own exception handling always converts a caught exception into a
 * response before finishing the request. Sub-requests (ESI includes, forwards) are skipped, since
 * only the main request corresponds to a real visiting session.
 */
final class ForgeOpsTrackerSessionListener implements EventSubscriberInterface
{
    private const CRASHED_ATTRIBUTE = '_forge_ops_tracker_crashed';

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::EXCEPTION => 'onKernelException',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::CRASHED_ATTRIBUTE, false);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::CRASHED_ATTRIBUTE, true);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $crashed = $request->attributes->get(self::CRASHED_ATTRIBUTE, false)
            || $event->getResponse()->getStatusCode() >= 500;

        ForgeOpsTracker::recordSession($crashed);
    }
}
