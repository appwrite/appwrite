<?php

namespace Appwrite\Event;

use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use Utopia\Database\Document;

class Realtime extends Event
{
    protected array $targets = [];

    public function __construct()
    {
    }

    /**
     * Get Realtime payload for this event.
     *
     * @return array
     */
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
     * Set targets for this realtime event.
     *
     * @param array $targets
     * @return array
     */
    public function setTargets(array $targets): self
    {
        $this->targets = $targets;
        return $this;
    }

    /**
     * Get targets for this realtime event.
     *
     * @return array
     */
    public function getTargets(): array
    {
        return $this->targets;
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

        $target = RealtimeAdapter::fromPayload(
            // Pass first, most verbose event pattern
            event: $allEvents[0],
            payload: $payload,
            project: $this->getProject(),
            database: $db,
            collection: $collection,
            bucket: $bucket,
        );

        $projectIds = !empty($this->getTargets())
            ? $this->getTargets()
            : [$target['projectId'] ?? $this->getProject()->getId()];

        RealtimeAdapter::send(
            projectId: $projectIds,
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
