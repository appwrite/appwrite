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
     *              [CONNECTION_ID] -> [
     *                  [query1, query2],  // Subscription group 0 - AND logic within group
     *                  [query3],          // Subscription group 1
     *                  [query4, query5],  // Subscription group 2 - OR logic across groups
     *              ]
     *
     * Each subscription group is an array of query strings.
     * Within a group: AND logic (all queries must match)
     * Across groups: OR logic (any group matching = send event)
     */
    public array $subscriptions = [];

    private PubSubPool $pubSubPool;

    public function __construct()
    {
        global $register;
        $this->pubSubPool = new PubSubPool($register->get('pools')->get('pubsub'));
    }

    /**
     * Adds a subscription group.
     *
     * @param string $projectId
     * @param mixed $identifier Connection ID
     * @param array $roles User roles
     * @param array $channels Channels to subscribe to
     * @param array $queryGroup Array of Query objects for this subscription group (AND logic within group)
     * @return void
     */
    public function subscribe(string $projectId, mixed $identifier, array $roles, array $channels, array $queryGroup = []): void
    {
        if (!isset($this->subscriptions[$projectId])) { // Init Project
            $this->subscriptions[$projectId] = [];
        }

        // Convert Query objects to strings for this subscription group
        $queryStrings = [];
        if (empty($queryGroup)) {
            // No queries means "listen to all events" - use select("*")
            $queryStrings[] = Query::select(['*'])->toString();
        } else {
            foreach ($queryGroup as $query) {
                /** @var Query $query */
                $queryStrings[] = $query->toString();
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
                // Add this query group as a new subscription group (array of query strings)
                $this->subscriptions[$projectId][$role][$channel][$identifier][] = $queryStrings;
            }
        }

        // Keep a complete view of channels for unsubscribe(), even if subscribe() is called repeatedly
        if (!isset($this->connections[$identifier])) {
            $this->connections[$identifier] = [
                'projectId' => $projectId,
                'roles' => $roles,
                'channels' => $channels
            ];
        } else {
            $this->connections[$identifier]['projectId'] = $projectId;
            $this->connections[$identifier]['roles'] = $roles;
            $this->connections[$identifier]['channels'] = \array_merge($this->connections[$identifier]['channels'], $channels);
        }
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
                        foreach ($this->subscriptions[$event['project']][$role][$channel] as $id => $subscriptionGroups) {
                            $matchedGroups = [];

                            // Process each subscription group (OR logic across groups)
                            foreach ($subscriptionGroups as $queryGroup) {
                                // Parse all queries in this group
                                $parsedQueries = [];
                                foreach ($queryGroup as $queryString) {
                                    $parsed = Query::parseQueries([$queryString]);
                                    $parsedQueries = array_merge($parsedQueries, $parsed);
                                }

                                // Check if this group matches (AND logic within group)
                                // RuntimeQuery::filter handles select("*") - returns payload if present
                                if (!empty(RuntimeQuery::filter($parsedQueries, $payload))) {
                                    // This group matched - add it to matched groups
                                    $matchedGroups[] = $queryGroup;
                                }
                            }

                            // Only add to receivers if at least one group matched
                            if (!empty($matchedGroups)) {
                                if (!isset($receivers[$id])) {
                                    $receivers[$id] = [];
                                }
                                // Store matched groups (each group is an array of query strings)
                                $receivers[$id] = array_merge($receivers[$id], $matchedGroups);
                            }
                        }
                        break;
                    }
                }
            }
        }

        // De-duplicate groups per connection (same connection can match via multiple roles)
        foreach ($receivers as $id => $groups) {
            $unique = [];
            foreach ($groups as $group) {
                $key = \json_encode($group);
                $unique[$key] = $group;
            }
            $receivers[$id] = \array_values($unique);
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
