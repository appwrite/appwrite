<?php

namespace Appwrite\Event;

use Appwrite\Messaging\Adapter;
use Appwrite\Messaging\Adapter\Realtime as RealtimeAdapter;
use Utopia\Console\Console;
use Utopia\Database\Document;
use Utopia\Database\Exception;
use Utopia\Database\Role;

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

        $projectIds = !empty($this->getSubscribers())
            ? $this->getSubscribers()
            : [$target['projectId'] ?? $this->getProject()->getId()];

        foreach ($projectIds as $projectId) {
            $roles = $this->resolveSubscriberRoles($projectId, $target['roles'], $target['projectId'] ?? null);
            if ($roles === null) {
                continue;
            }

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

    /**
     * Resolve the roles a subscriber's copy of this event is addressed with.
     *
     * Role names are tree-relative: `user:{id}` names a project user in the
     * project's subscription tree but a console user in the console tree, and
     * either identity can be minted by signing up with a colliding custom ID.
     * A copy delivered across trees with the roles of the other tree therefore
     * reaches whoever claimed the same ID, not the intended audience:
     *
     * - The console copy of a project-flavored event (an execution's `user:{id}`
     *   read roles) is re-addressed to the project's organization, which is the
     *   audience the console actually subscribes with. This matches the HTTP
     *   read boundary exactly: the console reads executions in admin mode, where
     *   `isPrivileged` drops the per-execution permission filter (see
     *   Functions/Http/Executions/Get.php), so every org member can already read
     *   every execution over HTTP. The org team role neither broadens nor
     *   narrows that set — it just carries the same audience over realtime.
     * - The project copy of a console-flavored event (rules and the like, keyed
     *   `team:{orgTeamId}`) is dropped: admin-mode connections subscribe with
     *   bare membership roles and never matched it, so only a project team
     *   minted with the colliding ID could receive it.
     *
     * @param array<int, string> $roles roles computed for the event's own tree
     * @return array<int, string>|null roles for this copy, or null to skip it
     */
    protected function resolveSubscriberRoles(string $subscriber, array $roles, ?string $targetProjectId): ?array
    {
        $project = $this->getProject();

        if ($subscriber === 'console' && $project->getId() !== 'console' && $targetProjectId !== 'console') {
            $teamId = $project->getAttribute('teamId');

            return empty($teamId) ? null : [Role::team($teamId)->toString()];
        }

        if ($subscriber !== 'console' && $targetProjectId === 'console') {
            return null;
        }

        return $roles;
    }
}
