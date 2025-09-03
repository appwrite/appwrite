<?php

namespace Appwrite\Event;

use Appwrite\Messaging\Adapter;
use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use Utopia\Database\Document;
use Utopia\Database\Exception;

class Realtime extends Event
{
    protected array $subscribers = [];

    private Adapter $realtime;

    protected bool $critical = false;

    public function __construct()
    {
        $this->realtime = new Adapter\Realtime();
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
     * Set subscribers for this realtime event.
     *
     * @param array $subscribers
     * @return self
     */
    public function setSubscribers(array $subscribers): self
    {
        $this->subscribers = $subscribers;
        return $this;
    }

    /**
     * Get subscribers for this realtime event.
     *
     * @return array
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /**
     * Execute Event.
     *
     * @return string|bool
     * @throws Exception
     */
    public function trigger(): string|bool
    {
        if ($this->paused || empty($this->event)) {
            return false;
        }

        $allEvents = Event::generateEvents($this->getEvent(), $this->getParams());

        $payload = new Document($this->getPayload());

        $db = $this->getContext('database');
        $bucket = $this->getContext('bucket');

        // Can be Tables API or Collections API; generated channels include both!
        $tableOrCollection = $this->getContext('table') ?? $this->getContext('collection');

        $target = RealtimeAdapter::fromPayload(
            event: $allEvents[0],
            payload: $payload,
            project: $this->getProject(),
            database: $db,
            collection: $tableOrCollection,
            bucket: $bucket,
        );

        $projectIds = !empty($this->getSubscribers())
            ? $this->getSubscribers()
            : [$target['projectId'] ?? $this->getProject()->getId()];

        foreach ($projectIds as $projectId) {
            $this->realtime->send(
                projectId: $projectId,
                payload: $this->getRealtimePayload(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'permissionsChanged' => $target['permissionsChanged'],
                    'userId' => $this->getParam('userId')
                ]
            );
        }

        return true;
    }
}
