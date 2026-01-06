<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;

class PaymentsUsage extends Event
{
    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(Event::PAYMENTS_USAGE_QUEUE_NAME)
            ->setClass(Event::PAYMENTS_USAGE_CLASS_NAME);
    }

    /**
     * Prepare the payload for the event
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->getProject(),
        ];
    }
}
