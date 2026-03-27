<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Event as DatabaseEvent;
use Utopia\Database\Hook\Lifecycle;

/**
 * Triggers function, webhook, and realtime events when users are created.
 *
 * Registered on dbForProject.
 */
class UserEvents implements Lifecycle
{
    public function __construct(
        private Document $project,
        private Response $response,
        private Event $events,
        private Func $functions,
        private Webhook $webhooks,
        private Realtime $realtime,
    ) {
    }

    public function handle(DatabaseEvent $event, mixed $data): void
    {
        if ($event !== DatabaseEvent::DocumentCreate) {
            return;
        }

        if (!$data instanceof Document || $data->getCollection() !== 'users') {
            return;
        }

        $this->events
            ->setEvent('users.[userId].create')
            ->setParam('userId', $data->getId())
            ->setPayload($this->response->output($data, Response::MODEL_USER));

        $this->functions
            ->from($this->events)
            ->trigger();

        if (!empty($this->project->getAttribute('webhooks'))) {
            $this->webhooks
                ->from($this->events)
                ->trigger();
        }

        if ($this->events->getProject()->getId() !== 'console') {
            $this->realtime
                ->from($this->events)
                ->trigger();
        }
    }
}
