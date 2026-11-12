<?php

namespace Appwrite\Alert;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Utopia\Response\Model\Alert as AlertModel;
use Utopia\Database\Document;

/**
 * Fires an alert's project event, `monitors.[monitorId].alerts.[alertId].<change>`,
 * with the alert as its payload: to the project's webhooks, the functions that
 * listen to it, and realtime. For callers outside the project's own request,
 * such as the evaluator or an organization route; a project route fires it
 * through the request's event instead.
 */
readonly class Events
{
    public function __construct(
        private Event $queueForEvents,
        private Webhook $queueForWebhooks,
        private FunctionPublisher $publisherForFunctions,
        private Realtime $queueForRealtime,
    ) {
    }

    /**
     * A monitor outside a project, which has no webhooks or functions, fires none.
     *
     * @throws \RuntimeException when a queue did not accept the event, so the caller can retry
     */
    public function trigger(Change $change, Document $alert, Document $project): void
    {
        if ($project->isEmpty() || $project->getId() === 'console' || (string) $alert->getAttribute('projectId', '') !== $project->getId()) {
            return;
        }

        $this->queueForEvents
            ->setProject($project)
            ->setEvent('monitors.[monitorId].alerts.[alertId].' . $change->value)
            ->setParam('monitorId', (string) $alert->getAttribute('monitorId'))
            ->setParam('alertId', $alert->getId())
            ->setPayload((new AlertModel())->payload($alert));

        if ($this->queueForWebhooks->from($this->queueForEvents)->trigger() === false) {
            throw new \RuntimeException('The alert event for webhooks was not accepted by the queue.');
        }

        $function = $this->publisherForFunctions->enqueue(FunctionMessage::fromEvent(
            event: $this->queueForEvents->getEvent(),
            params: $this->queueForEvents->getParams(),
            project: $this->queueForEvents->getProject(),
            payload: $this->queueForEvents->getPayload(),
        ));
        if ($function === false) {
            throw new \RuntimeException('The alert event for functions was not accepted by the queue.');
        }

        $this->queueForRealtime
            ->setSubscribers(['console', $project->getId()])
            ->from($this->queueForEvents)
            ->trigger();
    }
}
