<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Database\Event as DatabaseEvent;
use Utopia\Database\Event\Document\Created;
use Utopia\Database\Event\Domain;
use Utopia\Database\Hook\Lifecycle;
use Utopia\Database\Hook\Selective;

/**
 * Triggers function, webhook, and realtime events when users are created.
 *
 * Registered on dbForProject.
 */
class UserEvents implements Lifecycle, Selective
{
    public function __construct(
        private Document $project,
        private Response $response,
        private Event $source,
        private Event $events,
        private FunctionPublisher $functions,
        private Webhook $webhooks,
        private Realtime $realtime,
    ) {
    }

    #[\Override]
    public function handles(DatabaseEvent $event): bool
    {
        return $event === DatabaseEvent::DocumentCreate;
    }

    #[\Override]
    public function handle(Domain $event): void
    {
        if (!$event instanceof Created || $event->collection !== 'users') {
            return;
        }

        $user = $event->document;

        $this->events
            ->from($this->source)
            ->setProject($this->project)
            ->setEvent('users.[userId].create')
            ->setParam('userId', $user->getId())
            ->setPayload($this->response->output($user, Response::MODEL_USER));

        $this->functions->enqueue(FunctionMessage::fromEvent(
            event: $this->events->getEvent(),
            params: $this->events->getParams(),
            project: $this->events->getProject(),
            user: $this->events->getUser(),
            userId: $this->events->getUserId(),
            payload: $this->events->getPayload(),
            platform: $this->events->getPlatform(),
        ));

        if (!empty($this->project->getAttribute('webhooks'))) {
            $this->webhooks
                ->from($this->events)
                ->trigger();
        }

        if ($this->project->getId() !== 'console') {
            $this->realtime
                ->from($this->events)
                ->trigger();
        }
    }
}
