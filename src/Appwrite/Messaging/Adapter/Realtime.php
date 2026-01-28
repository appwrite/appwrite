<?php

namespace Appwrite\Messaging\Adapter;

use Appwrite\Messaging\Adapter as MessagingAdapter;
use Appwrite\PubSub\Adapter\Pool as PubSubPool;
use Appwrite\Utopia\Database\RuntimeQuery;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;

class Realtime extends MessagingAdapter
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
     *          [CHANNEL_NAME_X] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     *          [CHANNEL_NAME_Y] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     *          [CHANNEL_NAME_Z] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     *      [ROLE_Y] ->
     *          [CHANNEL_NAME_X] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     *          [CHANNEL_NAME_Y] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     *          [CHANNEL_NAME_Z] ->
     *              [CONNECTION_ID] -> [QUERY_KEY] => true
     */
    public array $subscriptions = [];

    private PubSubPool $pubSubPool;

    public function __construct()
    {
        global $register;
        $this->pubSubPool = new PubSubPool($register->get('pools')->get('pubsub'));
    }

    /**
     * Adds a subscription.
     *
     * @param string $projectId
     * @param mixed $identifier
     * @param array $roles
     * @param array $channels
     * @param array $queries
     * @return void
     */
    public function subscribe(string $projectId, mixed $identifier, array $roles, array $channels, array $queries = []): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        $queryKeys = [];
        if (empty($queries)) {
            $queryKeys[] = '';
        } else {
            foreach ($queries as $query) {
                /** @var Query $query */
                $queryKeys[] = $query->toString();
            }
        }

        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) { // Add user first connection
                $this->subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel => $list) {
                if (!isset($this->subscriptions[$projectId][$role][$channel][$identifier])) {
                    $this->subscriptions[$projectId][$role][$channel][$identifier] = [];
                }
                foreach ($queryKeys as $queryKey) {
                    $this->subscriptions[$projectId][$role][$channel][$identifier][$queryKey] = true;
                }
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
        $channels = $this->connections[$connection]['channels'] ?? [];

        foreach ($roles as $role) {
            foreach ($channels as $channel => $list) {
                unset($this->subscriptions[$projectId][$role][$channel][$connection]); // dropping connection will drop the queries as well

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
            && array_key_exists($channel, $this->subscriptions[$projectId][$role])
            && !empty($this->subscriptions[$projectId][$role][$channel]);
    }

    /**
     * Sends an event to the Realtime Server
     * @param string $projectId
     * @param array $payload
     * @param array $events
     * @param array $channels
     * @param array $roles
     * @param array $options
     * @return void
     * @throws \Exception
     */
    public function send(string $projectId, array $payload, array $events, array $channels, array $roles, array $options = []): void
    {
        if (empty($channels) || empty($roles) || empty($projectId)) {
            return;
        }

        $permissionsChanged = array_key_exists('permissionsChanged', $options) && $options['permissionsChanged'];
        $userId = array_key_exists('userId', $options) ? $options['userId'] : null;

        $this->pubSubPool->publish('realtime', json_encode([
            'project' => $projectId,
            'roles' => $roles,
            'permissionsChanged' => $permissionsChanged,
            'userId' => $userId,
            'data' => [
                'events' => $events,
                'channels' => $channels,
                'timestamp' => DateTime::formatTz(DateTime::now()),
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
     * @return int[]|string[]
     */
    public function getSubscribers(array $event): array
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
                        $payload = $event['data']['payload'] ?? [];
                        foreach ($this->subscriptions[$event['project']][$role][$channel] as $id => $queryMap) {
                            $matchedQueryKeys = [];
                            // for representing a all query subscribed channel
                            if (isset($queryMap[''])) {
                                $matchedQueryKeys[] = '';
                            } else {
                                foreach (array_keys($queryMap) as $queryKey) {
                                    $parsed = Query::parseQueries([$queryKey]);
                                    if (!empty(RuntimeQuery::filter($parsed, $payload))) {
                                        $matchedQueryKeys[] = $queryKey;
                                    }
                                }
                            }
                            $receivers[$id] = $matchedQueryKeys;
                        }
                        break;
                    }
                }
            }
        }

        return $receivers;
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
                case str_starts_with($key, 'account.'):
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
     * Converts the queries from the Query Params into an array.
     * @param array $queries
     * @return array
     */
    public static function convertQueries(array $queries): array
    {
        $queries = Query::parseQueries($queries);
        $stack = $queries;
        $allowedMethods = implode(', ', RuntimeQuery::ALLOWED_QUERIES);
        while (!empty($stack)) {
            /** `@var` Query $query */
            $query = array_pop($stack);
            $method = $query->getMethod();
            if (!in_array($method, RuntimeQuery::ALLOWED_QUERIES, true)) {
                $unsupportedMethod = $method;
                throw new QueryException(
                    "Query method '{$unsupportedMethod}' is not supported in Realtime queries. Allowed query methods are: {$allowedMethods}"
                );
            }
            if (in_array($method, [Query::TYPE_AND, Query::TYPE_OR], true)) {
                $stack = array_merge($stack, $query->getValues());
            }
        }

        return $queries;
    }

    /**
     * Create channels array based on the event name and payload.
     *
     * @param string $event
     * @param Document $payload
     * @param Document|null $project
     * @param Document|null $database
     * @param Document|null $collection
     * @param Document|null $bucket
     * @return array
     * @throws \Exception
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
            case 'rules':
            case 'migrations':
                $channels[] = 'console';
                $channels[] = 'projects.' . $project->getId();
                $projectId = 'console';
                $roles = [Role::team($project->getAttribute('teamId'))->toString()];
                break;
            case 'projects':
                $channels[] = 'console';
                $channels[] = 'projects.' . $parts[1];
                $projectId = 'console';
                $roles = [Role::team($project->getAttribute('teamId'))->toString()];
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
                $resource = $parts[4] ?? '';
                if (in_array($resource, ['columns', 'attributes', 'indexes'])) {
                    $channels[] = 'console';
                    $channels[] = 'projects.' . $project->getId();
                    $projectId = 'console';
                    $roles = [Role::team($project->getAttribute('teamId'))->toString()];
                } elseif (in_array($resource, ['rows', 'documents'])) {
                    if ($database->isEmpty()) {
                        throw new \Exception('Database needs to be passed to Realtime for Document/Row events in the Database.');
                    }
                    if ($collection->isEmpty()) {
                        throw new \Exception('Collection or the Table needs to be passed to Realtime for Document/Row events in the Database.');
                    }

                    $tableId = $payload->getAttribute('$tableId', '');
                    $collectionId = $payload->getAttribute('$collectionId', '');
                    $resourceId = $tableId ?: $collectionId;

                    $channels[] = 'rows';
                    $channels[] = 'databases.' . $database->getId() .  '.tables.' . $resourceId . '.rows';
                    $channels[] = 'databases.' . $database->getId() . '.tables.' . $resourceId . '.rows.' . $payload->getId();

                    $channels[] = 'documents';
                    $channels[] = 'databases.' . $database->getId() .  '.collections.' . $resourceId . '.documents';
                    $channels[] = 'databases.' . $database->getId() . '.collections.' . $resourceId . '.documents.' . $payload->getId();

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
                        $channels[] = 'projects.' . $project->getId();
                        $channels[] = 'executions';
                        $channels[] = 'executions.' . $payload->getId();
                        $channels[] = 'functions.' . $payload->getAttribute('functionId');
                        $roles = $payload->getRead();
                    }
                } elseif ($parts[2] === 'deployments') {
                    $channels[] = 'console';
                    $channels[] = 'projects.' . $project->getId();
                    $projectId = 'console';
                    $roles = [Role::team($project->getAttribute('teamId'))->toString()];
                }

                break;
            case 'sites':
                if ($parts[2] === 'deployments') {
                    $channels[] = 'console';
                    $channels[] = 'projects.' . $project->getId();
                    $projectId = 'console';
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
