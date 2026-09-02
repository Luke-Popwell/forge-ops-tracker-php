<?php

declare(strict_types=1);

namespace ForgeOps\Tracker\Integrations\Symfony;

use ForgeOps\Tracker\ForgeOpsTracker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Autowired/autoconfigured automatically if registered as a service
 * (Symfony's default for services under most skeleton config), or add
 * explicitly with the `kernel.event_subscriber` tag.
 */
final class ForgeOpsTrackerExceptionListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        // Reports, then leaves the event untouched -- doesn't call
        // setThrowable()/setResponse(), so Symfony's own exception
        // handling continues exactly as if this listener weren't
        // registered. Only fires for an exception that actually escaped
        // the controller uncaught; anything your own code already
        // catches never reaches here at all.
        ForgeOpsTracker::captureException($throwable, [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
        ]);
    }
}
