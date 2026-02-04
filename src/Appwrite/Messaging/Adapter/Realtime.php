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
     *              [CONNECTION_ID] ->
     *                  [SUB_ID] -> ['strings' => [...], 'parsed' => [...]]
     *
     * Each subscription ID maps to query strings (for metadata) and pre-parsed Query objects (for filtering).
     * Within a subscription: AND logic (all queries must match)
     * Across subscriptions: OR logic (any subscription matching = send event)
     */
    public array $subscriptions = [];

    private ?PubSubPool $pubSubPool = null;

    /**
     * Get the PubSubPool instance, initializing it lazily if needed.
     * This allows unit tests to work without requiring the global $register.
     *
     * @return PubSubPool
     */
    private function getPubSubPool(): PubSubPool
    {
        if ($this->pubSubPool === null) {
            global $register;
            $this->pubSubPool = new PubSubPool($register->get('pools')->get('pubsub'));
        }
        return $this->pubSubPool;
    }

    /**
     * Adds a subscription with a specific subscription ID.
     *
     * @param string $projectId
     * @param mixed $identifier Connection ID
     * @param string $subscriptionId Unique subscription ID
     * @param array $roles User roles
     * @param array $channels Channels to subscribe to (array of channel names)
     * @param array $queryGroup Array of Query objects for this subscription (AND logic within subscription)
     * @return void
     */
    public function subscribe(string $projectId, mixed $identifier, string $subscriptionId, array $roles, array $channels, array $queryGroup = []): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        // Convert Query objects to strings and store both for this subscription
        $queryStrings = [];
        $parsedQueries = [];
        if (empty($queryGroup)) {
            // No queries means "listen to all events" - use select("*")
            $selectAll = Query::select(['*']);
            $queryStrings[] = $selectAll->toString();
            $parsedQueries[] = $selectAll;
        } else {
            foreach ($queryGroup as $query) {
                /** @var Query $query */
                $queryStrings[] = $query->toString();
                $parsedQueries[] = $query;
            }
        }

        $subscriptionData = [
            'strings' => $queryStrings,
            'parsed' => $parsedQueries,
        ];

        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) {
                $this->subscriptions[$projectId][$role] = [];
            }

            foreach ($channels as $channel) {
                if (!isset($this->subscriptions[$projectId][$role][$channel])) {
                    $this->subscriptions[$projectId][$role][$channel] = [];
                }
                if (!isset($this->subscriptions[$projectId][$role][$channel][$identifier])) {
                    $this->subscriptions[$projectId][$role][$channel][$identifier] = [];
                }
                $this->subscriptions[$projectId][$role][$channel][$identifier][$subscriptionId] = $subscriptionData;
            }
        }

        // Update connection info
        $this->connections[$identifier] = [
            'projectId' => $projectId,
            'roles' => $roles,
            'channels' => $channels
        ];
    }

    /**
     * Get subscription metadata for a connection.
     * Retrieves subscription data including channels and queries directly from the subscriptions tree.
     *
     * @param mixed $connection Connection ID
     * @return array Array of [subscriptionId => ['channels' => string[], 'queries' => string[]]]
     */
    public function getSubscriptionMetadata(mixed $connection): array
    {
        $projectId = $this->connections[$connection]['projectId'] ?? null;
        $roles = $this->connections[$connection]['roles'] ?? [];
        $channels = $this->connections[$connection]['channels'] ?? [];

        if (!$projectId || empty($roles) || empty($channels)) {
            return [];
        }

        $subscriptions = [];

        // Extract subscription data from subscriptions tree
        foreach ($roles as $role) {
            if (!isset($this->subscriptions[$projectId][$role])) {
                continue;
            }

            foreach ($channels as $channel) {
                if (!isset($this->subscriptions[$projectId][$role][$channel][$connection])) {
                    continue;
                }

                foreach ($this->subscriptions[$projectId][$role][$channel][$connection] as $subId => $subscriptionData) {
                    if (!isset($subscriptions[$subId])) {
                        $subscriptions[$subId] = [
                            'channels' => [],
                            'queries' => $subscriptionData['strings'] ?? []
                        ];
                    }
                    if (!\in_array($channel, $subscriptions[$subId]['channels'])) {
                        $subscriptions[$subId]['channels'][] = $channel;
                    }
                }
            }
        }

        return $subscriptions;
    }

    /**
     * Removes all subscriptions for a connection.
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
            foreach ($channels as $channel) {
                unset($this->subscriptions[$projectId][$role][$channel][$connection]); // dropping connection will drop all subscriptions

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

        if (isset($this->connections[$connection])) {
            unset($this->connections[$connection]);
        }
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

        $this->getPubSubPool()->publish('realtime', json_encode([
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
     * @return array<int|string, array> Map of connection IDs to matched query groups
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
                        foreach ($this->subscriptions[$event['project']][$role][$channel] as $id => $subscriptions) {
                            $matchedSubscriptions = [];

                            // Process each subscription (OR logic across subscriptions)
                            foreach ($subscriptions as $subId => $subscriptionData) {
                                // Use pre-parsed queries instead of re-parsing on every event
                                $parsedQueries = $subscriptionData['parsed'] ?? [];
                                $queryStrings = $subscriptionData['strings'] ?? [];

                                // Check if this subscription matches (AND logic within subscription)
                                // Or if empty payload and select all as filter will return empty payload out of it even if it passed
                                $isEmptyPayloadAndSelectAll = !empty($parsedQueries) && RuntimeQuery::isSelectAll($parsedQueries[0]) && empty($payload);
                                if ($isEmptyPayloadAndSelectAll || !empty(RuntimeQuery::filter($parsedQueries, $payload))) {
                                    $matchedSubscriptions[$subId] = $queryStrings;
                                }
                            }

                            // Only add connection to receivers if at least one subscription matched
                            if (!empty($matchedSubscriptions)) {
                                if (!isset($receivers[$id])) {
                                    $receivers[$id] = [];
                                }
                                $receivers[$id] += $matchedSubscriptions;
                            }
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
     * Constructs subscriptions from query parameters.
     *
     * Reconstructs subscription structure from query params where subscription indices can span multiple channels.
     * Format: {channel}[subscriptionIndex][]=query1&{channel}[subscriptionIndex][]=query2
     *
     * Example:
     * - tests[0][]=select(*) → subscription 0: channels=["tests"]
     * - tests[1][]=equal(...) & prod[1][]=equal(...) → subscription 1: channels=["tests", "prod"]
     *
     * @param array $channelNames Array of channel names
     * @param callable $getQueryParam Callable that takes a channel name and returns its query param value (null if not present)
     * @return array Array indexed by subscription index: [index => ['channels' => string[], 'queries' => Query[]]]
     * @throws QueryException
     */
    public static function constructSubscriptions(array $channelNames, callable $getQueryParam): array
    {
        $subscriptionsByIndex = [];

        foreach ($channelNames as $channel) {
            $channelSubscriptions = $getQueryParam($channel);

            // Backward compatibility: if no channel-specific query params, treat as subscription 0 with select("*")
            if ($channelSubscriptions === null) {
                if (!isset($subscriptionsByIndex[0])) {
                    $subscriptionsByIndex[0] = [
                        'channels' => [],
                        'queries' => []
                    ];
                }
                $subscriptionsByIndex[0]['channels'][] = $channel;
                if (empty($subscriptionsByIndex[0]['queries'])) {
                    $subscriptionsByIndex[0]['queries'] = [Query::select(['*'])];
                }
                continue;
            }

            if (!is_array($channelSubscriptions)) {
                $channelSubscriptions = [$channelSubscriptions];
            }

            foreach ($channelSubscriptions as $subscriptionIndex => $subscription) {
                if (!isset($subscriptionsByIndex[$subscriptionIndex])) {
                    $subscriptionsByIndex[$subscriptionIndex] = [
                        'channels' => [],
                        'queries' => []
                    ];
                }

                if (!in_array($channel, $subscriptionsByIndex[$subscriptionIndex]['channels'])) {
                    $subscriptionsByIndex[$subscriptionIndex]['channels'][] = $channel;
                }

                if (empty($subscriptionsByIndex[$subscriptionIndex]['queries'])) {
                    $queriesToParse = is_array($subscription) ? $subscription : [$subscription];
                    $parsedQueries = self::convertQueries($queriesToParse);
                    $subscriptionsByIndex[$subscriptionIndex]['queries'] = $parsedQueries;
                }
            }
        }

        return $subscriptionsByIndex;
    }

    /**
     * Converts the queries from the Query Params into an array.
     * @param array|string $queries
     * @return array
     * @throws QueryException
     */
    public static function convertQueries(mixed $queries): array
    {
        $queries = Query::parseQueries($queries);
        $stack = $queries;
        $allowedMethods = implode(', ', RuntimeQuery::ALLOWED_QUERIES);
        while (!empty($stack)) {
            /** @var Query $query */
            $query = array_pop($stack);
            $method = $query->getMethod();
            if (!in_array($method, RuntimeQuery::ALLOWED_QUERIES, true)) {
                $unsupportedMethod = $method;
                throw new QueryException(
                    "Query method '{$unsupportedMethod}' is not supported in Realtime queries. Allowed query methods are: {$allowedMethods}"
                );
            }

            // Validate select queries - only select("*") is allowed
            if ($method === Query::TYPE_SELECT) {
                RuntimeQuery::validateSelectQuery($query);
            }

            if (in_array($method, [Query::TYPE_AND, Query::TYPE_OR], true)) {
                \array_push($stack, ...$query->getValues());
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
    public static function fromPayload(string $event, Document $payload, ?Document $project = null, ?Document $database = null, ?Document $collection = null, ?Document $bucket = null): array
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
