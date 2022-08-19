<?php

namespace Appwrite\Messaging\Adapter;

use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Appwrite\Messaging\Adapter;
use Utopia\App;
use Utopia\Database\ID;
use Utopia\Database\Role;

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
     * Adds a subscription.
     *
     * @param string $projectId
     * @param mixed $identifier
     * @param array $roles
     * @param array $channels
     * @return void
     */
    public function subscribe(string $projectId, mixed $identifier, array $roles, array $channels): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) { // Add user first connection
                $this->subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel => $list) {
                $this->subscriptions[$projectId][$role][$channel][$identifier] = true;
            }
        }

        $this->connections[$identifier] = [
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
        //TODO: look into moving it to an abstract class in the parent class
        if (empty($channel)) {
            return array_key_exists($projectId, $this->subscriptions)
                && array_key_exists($role, $this->subscriptions[$projectId]);
        }

        return array_key_exists($projectId, $this->subscriptions)
            && array_key_exists($role, $this->subscriptions[$projectId])
            && array_key_exists($channel, $this->subscriptions[$projectId][$role]);
    }

    /**
     * Sends an event to the Realtime Server
     * @param string $projectId
     * @param array $payload
     * @param string $event
     * @param array $channels
     * @param array $roles
     * @param array $options
     * @return void
     */
    public static function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        if (empty($channels) || empty($roles) || empty($projectId)) {
            return;
        }

        $permissionsChanged = array_key_exists('permissionsChanged', $options) && $options['permissionsChanged'];
        $userId = array_key_exists('userId', $options) ? $options['userId'] : null;

        $redis = new \Redis(); //TODO: make this part of the constructor
        $redis->connect(App::getEnv('_APP_REDIS_HOST', ''), App::getEnv('_APP_REDIS_PORT', ''));
        $redis->publish('realtime', json_encode([
            'project' => $projectId,
            'roles' => $roles,
            'permissionsChanged' => $permissionsChanged,
            'userId' => $userId,
            'data' => [
                'events' => $events,
                'channels' => $channels,
                'timestamp' => DateTime::now(),
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
    public function getSubscribers(array $event)
    {

        $receivers = [];
        /**
         * Check if project has subscriber.
         */
        if (isset($this->subscriptions[$event['project']])) {
            /**
             * Iterate through each role.
             */
            foreach ($this->subscriptions[$event['project']] as $role => $subscription) {
                /**
                 * Iterate through each channel.
                 */
                foreach ($event['data']['channels'] as $channel) {
                    /**
                     * Check if channel has subscriber. Also taking care of the role in the event and the wildcard role.
                     */
                    if (
                        \array_key_exists($channel, $this->subscriptions[$event['project']][$role])
                        && (\in_array($role, $event['roles']) || \in_array(Role::any()->toString(), $event['roles']))
                    ) {
                        /**
                         * Saving all connections that are allowed to receive this event.
                         */
                        foreach (array_keys($this->subscriptions[$event['project']][$role][$channel]) as $id) {
                            /**
                             * To prevent duplicates, we save the connections as array keys.
                             */
                            $receivers[$id] = 0;
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
     * @param string $userId
     * @return array
     */
    public static function convertChannels(array $channels, string $userId): array
    {
        $channels = array_flip($channels);

        foreach ($channels as $key => $value) {
            switch (true) {
                case strpos($key, 'account.') === 0:
                    unset($channels[$key]);
                    break;

                case $key === 'account':
                    if (!empty($userId)) {
                        $channels['account.' . $userId] = $value;
                    }
                    break;
            }
        }

        return $channels;
    }

    /**
     * Create channels array based on the event name and payload.
     *
     * @param string $event
     * @param Document $payload
     * @param Document|null $project
     * @return array
     */
    public static function fromPayload(string $event, Document $payload, Document $project = null, Document $database = null, Document $collection = null, Document $bucket = null): array
    {
        $channels = [];
        $roles = [];
        $permissionsChanged = false;
        $projectId = null;
        // TODO: add method here to remove all the magic index accesses
        $parts = explode('.', $event);

        switch ($parts[0]) {
            case 'users':
                $channels[] = 'account';
                $channels[] = 'account.' . $parts[1];
                $roles = [Role::user(ID::custom($parts[1]))->toString()];
                break;
            case 'teams':
                if ($parts[2] === 'memberships') {
                    $permissionsChanged = $parts[4] ?? false;
                    $channels[] = 'memberships';
                    $channels[] = 'memberships.' . $parts[3];
                } else {
                    $permissionsChanged = $parts[2] === 'create';
                    $channels[] = 'teams';
                    $channels[] = 'teams.' . $parts[1];
                }
                $roles = [Role::team(ID::custom($parts[1]))->toString()];
                break;
            case 'databases':
                if (in_array($parts[4] ?? [], ['attributes', 'indexes'])) {
                    $channels[] = 'console';
                    $projectId = 'console';
                    $roles = [Role::team($project->getAttribute('teamId'))->toString()];
                } elseif (($parts[4] ?? '') === 'documents') {
                    if ($database->isEmpty()) {
                        throw new \Exception('Database needs to be passed to Realtime for Document events in the Database.');
                    }
                    if ($collection->isEmpty()) {
                        throw new \Exception('Collection needs to be passed to Realtime for Document events in the Database.');
                    }

                    $channels[] = 'documents';
                    $channels[] = 'databases.' . $database->getId() .  '.collections.' . $payload->getCollection() . '.documents';
                    $channels[] = 'databases.' . $database->getId() . '.collections.' . $payload->getCollection() . '.documents.' . $payload->getId();

                    $roles = $collection->getAttribute('documentSecurity', false)
                        ? \array_merge($collection->getRead(), $payload->getRead())
                        : $collection->getRead();
                }
                break;
            case 'buckets':
                if ($parts[2] === 'files') {
                    if ($bucket->isEmpty()) {
                        throw new \Exception('Bucket needs to be passed to Realtime for File events in the Storage.');
                    }
                    $channels[] = 'files';
                    $channels[] = 'buckets.' . $payload->getAttribute('bucketId') . '.files';
                    $channels[] = 'buckets.' . $payload->getAttribute('bucketId') . '.files.' . $payload->getId();

                    $roles = $bucket->getAttribute('fileSecurity', false)
                        ? \array_merge($bucket->getRead(), $payload->getRead())
                        : $bucket->getRead();
                }

                break;

            case 'functions':
                if ($parts[2] === 'executions') {
                    if (!empty($payload->getRead())) {
                        $channels[] = 'console';
                        $channels[] = 'executions';
                        $channels[] = 'executions.' . $payload->getId();
                        $channels[] = 'functions.' . $payload->getAttribute('functionId');
                        $roles = $payload->getRead();
                    }
                } elseif ($parts[2] === 'deployments') {
                    $channels[] = 'console';

                    $roles = [Role::team($project->getAttribute('teamId'))->toString()];
                }

                break;
        }

        return [
            'channels' => $channels,
            'roles' => $roles,
            'permissionsChanged' => $permissionsChanged,
            'projectId' => $projectId
        ];
    }
}
