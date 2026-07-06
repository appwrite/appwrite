<?php

namespace Appwrite\Bus\Listeners;

use Appwrite\Bus\Events\ResourceEvent;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Functions\EventProcessor;
use Appwrite\Utopia\Response;
use Utopia\Bus\Listener;
use Utopia\Database\Database;

/**
 * Enqueues function executions for resource events that match a function's
 * subscribed events. Mirrors the legacy shutdown fan-out, keyed off the
 * project's cached function-event map, and projects the raw document through
 * its response model before delivery.
 */
class Functions extends Listener
{
    public static function getName(): string
    {
        return 'functions';
    }

    public static function getEvents(): array
    {
        return [ResourceEvent::class];
    }

    public function __construct()
    {
        $this
            ->desc('Enqueues function executions for matching resource events')
            ->inject('dbForProject')
            ->inject('publisherForFunctions')
            ->inject('eventProcessor')
            ->inject('response')
            ->callback($this->handle(...));
    }

    public function handle(ResourceEvent $event, Database $dbForProject, FunctionPublisher $publisherForFunctions, EventProcessor $eventProcessor, Response $response): void
    {
        if (empty($event->event) || $event->project === null) {
            return;
        }

        $functionsEvents = $eventProcessor->getFunctionsEvents($event->project, $dbForProject);
        if (empty($functionsEvents)) {
            return;
        }

        $generatedEvents = QueueEvent::generateEvents($event->event, $event->params);

        foreach ($generatedEvents as $generated) {
            if (isset($functionsEvents[$generated])) {
                $payload = $response->applyFilters(
                    $response->output($event->document, $event->model),
                    $event->model,
                    raw: $event->document,
                );

                $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                    event: $event->event,
                    params: $event->params,
                    project: $event->project,
                    user: $event->user,
                    userId: $event->userId,
                    payload: $payload,
                ));
                break;
            }
        }
    }
}
