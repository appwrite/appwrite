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
    // Account and user events reach realtime so a console user's connections end with their access.
    public const CONSOLE_ALLOWLIST = ['presences', 'teams', 'account', 'users'];

    /**
     * Membership payload fields the project's membership privacy policy governs, mapped to the
     * `auths` setting that makes them visible and to that setting's default for older projects.
     *
     * Mirrors the read endpoints (Teams/Http/Memberships/Get.php, XList.php).
     */
    private const MEMBERSHIP_PRIVACY = [
        // Default should be "false", but existing projects already rely on this being "true"
        'userName' => ['membershipsUserName', true],
        'userEmail' => ['membershipsUserEmail', true],
        'userPhone' => ['membershipsUserPhone', true],
        'userId' => ['membershipsUserId', true],
        'mfa' => ['membershipsMfa', true],
        'userAccessedAt' => ['membershipsUserAccessedAt', false],
    ];

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
        $hidden = $this->sensitive + $this->hiddenMembershipFields();

        $payload = [];

        foreach ($this->payload as $key => $value) {
            if (!isset($hidden[$key])) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * Membership fields the project's privacy policy hides from the team.
     *
     * A membership event is addressed to `team:{teamId}`, which every confirmed member holds, and
     * the payload is the HTTP response rendered for the *writer*: an API key or an admin-mode
     * caller renders the member's real name and email. The policy is therefore applied again here,
     * with no privileged or self exemption, so the one payload every member receives carries only
     * what the least privileged of them may read.
     *
     * @return array<string, true>
     */
    private function hiddenMembershipFields(): array
    {
        $parts = \explode('.', $this->event);

        if ($parts[0] !== 'teams' || ($parts[2] ?? '') !== 'memberships') {
            return [];
        }

        $auths = $this->project?->getAttribute('auths', []) ?? [];

        $hidden = [];

        foreach (self::MEMBERSHIP_PRIVACY as $field => [$setting, $default]) {
            if (!($auths[$setting] ?? $default)) {
                $hidden[$field] = true;
            }
        }

        return $hidden;
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

        $project = $this->getProject();
        $targetProjectId = $target['projectId'] ?? null;

        foreach ($projectIds as $projectId) {
            // A console-scoped event (rules, deployments) is published to
            // ['console', projectId] but carries the project's console read roles;
            // in the project tree those could match a colliding project-local team.
            if ($projectId !== 'console' && $targetProjectId === 'console') {
                continue;
            }

            // A project's roles mean nothing in the console, where they could match an unrelated account with the same ID
            $roles = $projectId === 'console' && $project?->getId() !== 'console' && $targetProjectId !== 'console'
                ? ($project?->getPermissionsByType(PermissionType::Read) ?? [])
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
