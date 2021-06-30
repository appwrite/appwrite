<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Database\Document;
use Appwrite\Messaging\Adapter;
use Utopia\App;

class Realtime extends Adapter
{
    /**
     * Connection Tree
     * 
     * [CONNECTION_ID] -> 
     *      'projectId' -> [PROJECT_ID]
     *      'roles' -> [ROLE_x, ROLE_Y]
     *      'channels' -> [CHANNEL_NAME_X, CHANNEL_NAME_Y, CHANNEL_NAME_Z]
     */
    public array $connections = [];

    /**
     * Subscription Tree
     * 
     * [PROJECT_ID] -> 
     *      [ROLE_X] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     *      [ROLE_Y] -> 
     *          [CHANNEL_NAME_X] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Y] -> [CONNECTION_ID]
     *          [CHANNEL_NAME_Z] -> [CONNECTION_ID]
     */
    public array $subscriptions = [];

    /**
     * Adds a subscribtion.
     * @param string $projectId Project ID.
     * @param mixed $connection Unique Identifier - Connection ID.
     * @param array $roles Roles of the Subscription.
     * @param array $channels Subscribed Channels.
     * @return void 
     */
    public function subscribe(string $projectId, mixed $connection, array $roles, array $channels): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) { // Add user first connection
                $this->subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel => $list) {
                $this->subscriptions[$projectId][$role][$channel][$connection] = true;
            }
        }

        $this->connections[$connection] = [
            'projectId' => $projectId,
            'roles' => $roles,
            'channels' => $channels
        ];
    }

    /**
     * Removes Subscription. 
     * 
     * @param mixed $connection
     * @return void 
     */
    public function unsubscribe(mixed $connection): void
    {
        $projectId = $this->connections[$connection]['projectId'] ?? '';
        $roles = $this->connections[$connection]['roles'] ?? [];

        foreach ($roles as $role) {
            foreach ($this->subscriptions[$projectId][$role] as $channel => $list) {
                unset($this->subscriptions[$projectId][$role][$channel][$connection]); // Remove connection

                if (empty($this->subscriptions[$projectId][$role][$channel])) {
                    unset($this->subscriptions[$projectId][$role][$channel]);  // Remove channel when no connections
                }
            }

            if (empty($this->subscriptions[$projectId][$role])) {
                unset($this->subscriptions[$projectId][$role]); // Remove role when no channels
            }
        }

        if (empty($this->subscriptions[$projectId])) { // Remove project when no roles
            unset($this->subscriptions[$projectId]);
        }

        unset($this->connections[$connection]);
    }

    /**
     * Checks if Channel has a subscriber.
     * @param string $projectId 
     * @param string $role 
     * @param string $channel 
     * @return bool 
     */
    public function hasSubscriber(string $projectId, string $role, string $channel = ''): bool
    {
        if (empty($channel)) {
            return array_key_exists($projectId, $this->subscriptions)
                && array_key_exists($role, $this->subscriptions[$projectId]);
        }

        return array_key_exists($projectId, $this->subscriptions)
            && array_key_exists($role, $this->subscriptions[$projectId])
            && array_key_exists($channel, $this->subscriptions[$projectId][$role]);
    }

    /**
     * Sends an event to the Realtime Server.
     * @param string $project 
     * @param array $payload 
     * @param string $event 
     * @param array $channels 
     * @param array $permissions 
     * @param array $options 
     * @return void 
     */
    public static function send(string $project, array $payload, string $event, array $channels, array $permissions, array $options = []): void
    {
        if (empty($channels) || empty($permissions) || empty($project)) return;

        $permissionsChanged = array_key_exists('permissionsChanged', $options) && $options['permissionsChanged'];
        $userId = array_key_exists('userId', $options) ? $options['userId'] : null;

        $redis = new \Redis();
        $redis->connect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
        $redis->publish('realtime', json_encode([
            'project' => $project,
            'permissions' => $permissions,
            'permissionsChanged' => $permissionsChanged,
            'userId' => $userId,
            'data' => [
                'event' => $event,
                'channels' => $channels,
                'timestamp' => time(),
                'payload' => $payload
            ]
        ]));
    }

    /**
     * Identifies the receivers of all subscriptions, based on the permissions and event.
     * 
     * Example of performance with an event with user:XXX permissions and with X users spread across 10 different channels:
     *  - 0.014 ms (±6.88%) | 10 Connections / 100 Subscriptions 
     *  - 0.070 ms (±3.71%) | 100 Connections / 1,000 Subscriptions 
     *  - 0.846 ms (±2.74%) | 1,000 Connections / 10,000 Subscriptions
     *  - 10.866 ms (±1.01%) | 10,000 Connections / 100,000 Subscriptions
     *  - 110.201 ms (±2.32%) | 100,000 Connections / 1,000,000 Subscriptions
     *  - 1,121.328 ms (±0.84%) | 1,000,000 Connections / 10,000,000 Subscriptions 
     * 
     * @param array $event
     */
    public function getReceivers(array $event)
    {
        $receivers = [];
        if (isset($this->subscriptions[$event['project']])) {
            foreach ($this->subscriptions[$event['project']] as $role => $subscription) {
                foreach ($event['data']['channels'] as $channel) {
                    if (
                        \array_key_exists($channel, $this->subscriptions[$event['project']][$role])
                        && (\in_array($role, $event['permissions']) || \in_array('*', $event['permissions']))
                    ) {
                        foreach (array_keys($this->subscriptions[$event['project']][$role][$channel]) as $ids) {
                            $receivers[$ids] = 0;
                        }
                        break;
                    }
                }
            }
        }

        return array_keys($receivers);
    }

    /**
     * Converts the channels from the Query Params into an array. 
     * Also renames the account channel to account.USER_ID and removes all illegal account channel variations.
     * @param array $channels 
     * @param Document $user 
     * @return array 
     */
    public static function convertChannels(array $channels, Document $user): array
    {
        $channels = array_flip($channels);

        foreach ($channels as $key => $value) {
            switch (true) {
                case strpos($key, 'account.') === 0:
                    unset($channels[$key]);
                    break;

                case $key === 'account':
                    if (!empty($user->getId())) {
                        $channels['account.' . $user->getId()] = $value;
                    }
                    unset($channels['account']);
                    break;
            }
        }

        if (\array_key_exists('account', $channels)) {
            if ($user->getId()) {
                $channels['account.' . $user->getId()] = $channels['account'];
            }
            unset($channels['account']);
        }

        return $channels;
    }

    /**
     * Create channels array based on the event name and payload.
     *
     * @return void
     */
    public static function fromPayload(string $event, Document $payload): array
    {
        $channels = [];
        $permissions = [];
        $permissionsChanged = false;

        switch (true) {
            case strpos($event, 'account.recovery.') === 0:
            case strpos($event, 'account.sessions.') === 0:
            case strpos($event, 'account.verification.') === 0:
                $channels[] = 'account.' . $payload->getAttribute('userId');
                $permissions = ['user:' . $payload->getAttribute('userId')];

                break;
            case strpos($event, 'account.') === 0:
                $channels[] = 'account.' . $payload->getId();
                $permissions = ['user:' . $payload->getId()];

                break;
            case strpos($event, 'teams.memberships') === 0:
                $permissionsChanged = in_array($event, ['teams.memberships.update', 'teams.memberships.delete', 'teams.memberships.update.status']);
                $channels[] = 'memberships';
                $channels[] = 'memberships.' . $payload->getId();
                $permissions = ['team:' . $payload->getAttribute('teamId')];

                break;
            case strpos($event, 'teams.') === 0:
                $permissionsChanged = $event === 'teams.create';
                $channels[] = 'teams';
                $channels[] = 'teams.' . $payload->getId();
                $permissions = ['team:' . $payload->getId()];

                break;
            case strpos($event, 'database.collections.') === 0:
                $channels[] = 'collections';
                $channels[] = 'collections.' . $payload->getId();
                $permissions = $payload->getAttribute('$permissions.read');

                break;
            case strpos($event, 'database.documents.') === 0:
                $channels[] = 'documents';
                $channels[] = 'collections.' . $payload->getAttribute('$collection') . '.documents';
                $channels[] = 'documents.' . $payload->getId();
                $permissions = $payload->getAttribute('$permissions.read');

                break;
            case strpos($event, 'storage.') === 0:
                $channels[] = 'files';
                $channels[] = 'files.' . $payload->getId();
                $permissions = $payload->getAttribute('$permissions.read');

                break;
            case strpos($event, 'functions.executions.') === 0:
                if (!empty($payload->getAttribute('$permissions.read'))) {
                    $channels[] = 'executions';
                    $channels[] = 'executions.' . $payload->getId();
                    $channels[] = 'functions.' . $payload->getAttribute('functionId');
                    $permissions = $payload->getAttribute('$permissions.read');
                }
                break;
        }

        return [
            'channels' => $channels,
            'permissions' => $permissions,
            'permissionsChanged' => $permissionsChanged
        ];
    }
}
