<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;

class Webhook extends Event
{
    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::WEBHOOK_QUEUE_NAME)
            ->setClass(Event::WEBHOOK_CLASS_NAME);
    }

    /**
     * Trim the payload for the webhook event.
     *
     * @return array
     */
    public function trimPayload(): array
    {
        $trimmed = parent::trimPayload();
        if (isset($this->context)) {
            $trimmed['context'] = [];
        }
        return $trimmed;
    }
}
