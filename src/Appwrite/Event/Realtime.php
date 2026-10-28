<?php

namespace Appwrite\Event;

use Appwrite\Messaging\Adapter;
use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use Utopia\Console\Console;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\PermissionType;

class Realtime extends Event
{
    public const CONSOLE_ALLOWLIST = ['presences', 'teams'];

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
     * Reset the event state for long-lived worker processes.
     *
     * `Event::reset()` clears params/sensitive/event/payload only. Realtime routing also
     * depends on `context`, `subscribers`, and `project`/`user` fields, so we clear them too
     * to prevent stale state from affecting subsequent triggers.
     */
    public function reset(): self
    {
        parent::reset();

        $this->subscribers = [];
        $this->context = [];
        $this->project = null;
        $this->user = null;
        $this->userId = null;

        return $this;
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

        $allEvents = Event::generateEvents($this->getEvent(), $this->getParams(), $this->getContext('database'));

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

        $project = $this->getProject();

        $projectIds = !empty($this->getSubscribers())
            ? $this->getSubscribers()
            : [$target['projectId'] ?? $project->getId()];

        foreach ($projectIds as $projectId) {
            // A resource's own read roles (e.g. an execution's user:{id}) are
            // meaningful only in its project's subscription tree. Delivered to
            // the console tree the same role matches an unrelated console
            // account with that id, so the console copy is re-addressed to the
            // project's read roles — the org members who may actually read it.
            $roles = $projectId === 'console' && $project->getId() !== 'console' && ($target['projectId'] ?? null) !== 'console'
                ? $project->getPermissionsByType(PermissionType::Read)
                : $target['roles'];

            try {
                $this->realtime->send(
                    projectId: $projectId,
                    payload: $this->getRealtimePayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $roles,
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $this->getParam('userId')
                    ]
                );
            } catch (\Exception $e) {
                Console::error('Realtime send failed: '.$e->getMessage());
            }
        }

        return true;
    }
}
