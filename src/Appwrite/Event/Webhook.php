<?php

namespace Appwrite\Event;

use Utopia\Database\Id;
use Utopia\Queue\Publisher\Synchronous as Publisher;
use Utopia\System\System;

class Webhook extends Event
{
    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_WEBHOOK_QUEUE_NAME', Event::WEBHOOK_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_WEBHOOK_CLASS_NAME', Event::WEBHOOK_CLASS_NAME));
    }

    /**
     * Name each triggered event, so the worker can tell a redelivery of it from a new one.
     *
     * The id travels in the payload rather than relying on the message pid: the
     * Redis broker requeues a retried message under a fresh pid, while the payload
     * is carried through every retry, requeue and broker migration unchanged.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return \array_merge(parent::preparePayload(), [
            'eventId' => Id::unique(),
        ]);
    }

    /**
     * Trim the payload for the webhook event.
     *
     * @return array
     */
    public function trimPayload(): array
    {
        $trimmed = parent::trimPayload();
        if (!empty($this->context)) {
            $trimmed['context'] = [];
        }
        return $trimmed;
    }
}
