<?php

namespace Appwrite\Event;

use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use Utopia\Database\Document;

class Realtime extends Event
{
    public function __construct()
    {
    }

    public function getRealtimePayload(): array
    {
        $payload = [];

        foreach ($this->payload as $key => $value) {
            if (!isset($this->sensitive[$key])) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Execute Event.
     *
     * @return string|bool
     * @throws InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        if ($this->paused || empty($this->event)) {
            return false;
        }

        $allEvents = Event::generateEvents($this->getEvent(), $this->getParams());
        $payload = new Document($this->getPayload());

        $db = $this->getContext('database');
        $collection = $this->getContext('collection');
        $bucket = $this->getContext('bucket');

        // var_dump("-------------------- In Realtime trigger ---------------");
        // var_dump($this->getProject());

        $target = RealtimeAdapter::fromPayload(
            // Pass first, most verbose event pattern
            event: $allEvents[0],
            payload: $payload,
            project: $this->getProject(),
            database: $db,
            collection: $collection,
            bucket: $bucket,
        );

        RealtimeAdapter::send(
            projectId: $target['projectId'] ?? $this->getProject()->getId(),
            payload: $this->getRealtimePayload(),
            events: $allEvents,
            channels: $target['channels'],
            roles: $target['roles'],
            options: [
                'permissionsChanged' => $target['permissionsChanged'],
                'userId' => $this->getParam('userId')
            ]
        );

        return true;
    }
}
