<?php

namespace Appwrite\Event;

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
     * Prepare the payload for the webhook event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        $prepared = parent::preparePayload();

        // Queue decoders turn nested JSON objects into associative arrays.
        // Keep the original body only when that conversion changes its shape.
        $body = \json_encode($this->payload) ?: '';
        if ($body !== \json_encode(\json_decode($body, true))) {
            $prepared['body'] = $body;
        }

        return $prepared;
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
