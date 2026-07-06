<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ResourceEvent;
use Appwrite\Event\Realtime as RealtimeEvent;
use Appwrite\Utopia\Response;
use Utopia\Bus\Listener;

/**
 * Broadcasts resource lifecycle events to realtime (WebSocket) subscribers.
 *
 * Subscribes to every {@see ResourceEvent}, projects the raw document through its
 * response model, and drives the request-scoped realtime event — which computes the
 * affected channels/roles and, only if any exist, publishes to the Redis "realtime"
 * pub/sub channel consumed by the realtime servers.
 */
class Realtime extends Listener
{
    public static function getName(): string
    {
        return 'realtime';
    }

    public static function getEvents(): array
    {
        return [ResourceEvent::class];
    }

    public function __construct()
    {
        $this
            ->desc('Broadcasts resource events to realtime subscribers')
            ->inject('queueForRealtime')
            ->inject('response')
            ->callback($this->handle(...));
    }

    public function handle(ResourceEvent $event, RealtimeEvent $queueForRealtime, Response $response): void
    {
        if (empty($event->event) || $event->project === null) {
            return;
        }

        // Preserve the legacy console realtime gate: the console project only receives
        // realtime for allow-listed route groups (e.g. presences), none of which are
        // migrated to typed events yet. Generalise this when they are.
        if ($event->project->getId() === 'console') {
            return;
        }

        $payload = $response->applyFilters(
            $response->output($event->document, $event->model),
            $event->model,
            raw: $event->document,
        );

        $queueForRealtime
            ->setEvent($event->event)
            ->setPayload($payload, $event->sensitive)
            ->setProject($event->project)
            ->setSubscribers($event->subscribers);

        foreach ($event->params as $key => $value) {
            $queueForRealtime->setParam($key, $value);
        }

        if ($event->user !== null) {
            $queueForRealtime->setUser($event->user);
        }

        if ($event->userId !== null) {
            $queueForRealtime->setUserId($event->userId);
        }

        foreach ($event->context as $key => $document) {
            $queueForRealtime->setContext($key, $document);
        }

        $queueForRealtime->trigger();
    }
}
