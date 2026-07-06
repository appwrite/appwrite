<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ResourceEvent;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Webhook as WebhookEvent;
use Appwrite\Functions\EventProcessor;
use Appwrite\Utopia\Response;
use Utopia\Bus\Listener;

/**
 * Triggers project webhooks for resource events that match a webhook's
 * subscribed events. Mirrors the legacy shutdown fan-out, keyed off the
 * project's cached webhook-event map, and projects the raw document through
 * its response model before delivery.
 */
class Webhook extends Listener
{
    public static function getName(): string
    {
        return 'webhook';
    }

    public static function getEvents(): array
    {
        return [ResourceEvent::class];
    }

    public function __construct()
    {
        $this
            ->desc('Triggers project webhooks for matching resource events')
            ->inject('eventProcessor')
            ->inject('queueForWebhooks')
            ->inject('response')
            ->callback($this->handle(...));
    }

    public function handle(ResourceEvent $event, EventProcessor $eventProcessor, WebhookEvent $queueForWebhooks, Response $response): void
    {
        if (empty($event->event) || $event->project === null) {
            return;
        }

        $webhooksEvents = $eventProcessor->getWebhooksEvents($event->project);
        if (empty($webhooksEvents)) {
            return;
        }

        $generatedEvents = QueueEvent::generateEvents($event->event, $event->params);

        foreach ($generatedEvents as $generated) {
            if (isset($webhooksEvents[$generated])) {
                $payload = $response->applyFilters(
                    $response->output($event->document, $event->model),
                    $event->model,
                    raw: $event->document,
                );

                $queueForWebhooks
                    ->setEvent($event->event)
                    ->setPayload($payload, $event->sensitive)
                    ->setProject($event->project);

                foreach ($event->params as $key => $value) {
                    $queueForWebhooks->setParam($key, $value);
                }

                if ($event->user !== null) {
                    $queueForWebhooks->setUser($event->user);
                }

                if ($event->userId !== null) {
                    $queueForWebhooks->setUserId($event->userId);
                }

                $queueForWebhooks->trigger();
                break;
            }
        }
    }
}
