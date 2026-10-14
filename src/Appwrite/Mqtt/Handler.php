<?php

namespace Appwrite\Mqtt;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Abuse\Adapter\TimeLimit\Redis as TimeLimitRedis;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Handler as MqttHandler;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsuback;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;
use Utopia\Span\Span;
use Utopia\System\System;

class Handler implements MqttHandler
{
    private const CURSOR_TTL = APP_PUSH_LEDGER_RETENTION;

    // The reserved per-user topic namespace. `users/<userId>` is an implicit topic owned by that
    // user alone: it needs no topics document (the publisher auto-provisions one) and a client may
    // subscribe only to its own. Wildcards under it are refused so they can't span other users.
    private const USER_TOPIC_PREFIX = 'users';

    /** @var array<string, array<string, array<int, Connection>>> project => userId => fd => connection */
    private array $connections = [];

    private int $revocations = 0;

    /** @var array<string, array<string, int>> project => userId => revocation, kept only while a CONNECT is in flight */
    private array $revoked = [];

    private int $connecting = 0;

    /** @param \Closure(): \DateTimeImmutable $clock */
    public function __construct(
        private readonly Container $container,
        private readonly Mqtt $mqtt,
        private readonly \Closure $getCache,
        private readonly \Closure $planForUser,
        private readonly \Closure $clock,
    ) {
    }

    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        $authMethod = $connect->authMethod ?? '';

        $this->untrack($connection);
        $connection->prefix = $connect->userProperties()['projectId'] ?? '';
        Span::add('project.id', $connection->prefix);
        Span::add('mqtt.clean_start', $connect->cleanStart);
        Span::add('mqtt.auth_method', $authMethod);

        $identity = $this->admit($connection, $authMethod, $connect->authData ?? '');

        if ($identity === []) {
            Span::add('mqtt.result', 'rejected');
            $this->mqtt->connectRefused->add(1, ['reason' => 'not_authorized']);
            return $this->refuseConnect(Connack::NOT_AUTHORIZED, Exception::USER_UNAUTHORIZED, $authMethod);
        }

        Span::add('user.id', $identity['userId'] ?? '');

        // Rate-limit CONNECT per user (keyed on userId until the infra surfaces client IP).
        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            $getRedis = $this->container->get('getRedis');
            $timeLimit = new TimeLimitRedis('mqtt:connect:{userId}', 128, 60, $getRedis())->withParams(['{userId}' => (string) ($identity['userId'] ?? '')]);

            if ($timeLimit->check()->limited) {
                Span::add('mqtt.result', 'abuse');
                $this->mqtt->connectRefused->add(1, ['reason' => 'rate_limited']);
                return $this->refuseConnect(Connack::QUOTA_EXCEEDED, Exception::GENERAL_RATE_LIMIT_EXCEEDED, $authMethod);
            }
        }

        // The per-device session anchor: the client id, or an account-level fallback so reconnects
        // resume the same session (the offline-replay cursor is keyed on it).
        $clientId = $connect->clientId;
        if ($clientId === '') {
            $clientId = 'custom_' . $connection->prefix . '_' . ($identity['userId'] ?? '');
        }
        $connection->setClientId($clientId);
        Span::add('mqtt.client_id', $connection->getClientId());

        if ($connection->closed) {
            Span::add('mqtt.result', 'closed');
            return Connack::refuse(Connack::UNSPECIFIED_ERROR);
        }

        $this->mqtt->recordConnection($connection->prefix);

        return Connack::accept(properties: $this->connackProperties($authMethod));
    }

    /**
     * A revoke that lands while the authenticator runs finds nothing indexed yet, so a user revoked
     * since this CONNECT began is checked again once indexed.
     *
     * @return array<string, mixed> the identity, or [] when refused
     */
    private function admit(Connection $connection, string $authMethod, string $credential): array
    {
        $this->connecting++;
        $revocation = $this->revocations;

        try {
            $authenticator = $this->container->get('authenticator');

            $start = microtime(true);
            $identity = $authenticator($connection->prefix, $authMethod, $credential);
            $this->mqtt->authDuration->record(microtime(true) - $start);

            if ($identity === []) {
                return [];
            }

            $connection->identity = $identity;
            $this->track($connection);

            $revokedAt = $this->revoked[$identity['projectId'] ?? ''][$identity['userId'] ?? ''] ?? 0;
            if ($revokedAt > $revocation && !$this->authorized($identity)) {
                $this->untrack($connection);
                $connection->identity = [];

                return [];
            }

            return $identity;
        } finally {
            if (--$this->connecting === 0) {
                $this->revoked = [];
            }
        }
    }

    /**
     * Refuse a CONNECT with an MQTT reason code, echoing the enhanced-auth method (see
     * connackProperties) and carrying the matching Appwrite error message as the Reason String.
     */
    private function refuseConnect(int $reasonCode, string $error, string $authMethod): Connack
    {
        return Connack::refuse($reasonCode, $this->connackProperties($authMethod, $error));
    }

    /**
     * CONNACK properties for a connection. MQTT 5.0 (§3.2.2.3.10) requires the server to echo the
     * CONNECT's Authentication Method on the CONNACK for enhanced auth, or strict clients (e.g.
     * HiveMQ) reject it with "Auth method in CONNACK must be present". A refusal also carries the
     * Appwrite error message as the Reason String (0x1F). Returns null when there is nothing to add.
     */
    private function connackProperties(string $authMethod, ?string $error = null): ?Properties
    {
        $properties = new Properties();

        if ($authMethod !== '') {
            $properties->add(new Property(Property::AUTHENTICATION_METHOD, $authMethod));
        }

        if ($error !== null) {
            $properties->add(new Property(Property::REASON_STRING, (new Exception($error))->getMessage()));
        }

        return $properties->all() === [] ? null : $properties;
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect
    {
        $projectId = $auth->userProperties()['projectId'] ?? '';

        $identity = [];
        if ($projectId !== '' && $projectId === $connection->prefix) {
            $authenticator = $this->container->get('authenticator');
            $start = microtime(true);
            $identity = $authenticator($projectId, $auth->method, $auth->data);
            $this->mqtt->authDuration->record(microtime(true) - $start);
        }

        // Reauth only refreshes the credential for the identity resolved at CONNECT — it must not
        // switch user or project, which would leak the previous user's subscriptions.
        if ($identity === [] || ($identity['userId'] ?? '') !== ($connection->identity['userId'] ?? '')) {
            $this->mqtt->reauth->add(1, ['result' => 'rejected']);
            Span::add('mqtt.result', 'rejected');
            return Disconnect::refuse(Disconnect::NOT_AUTHORIZED, (new Exception(Exception::USER_UNAUTHORIZED))->getMessage());
        }

        $connection->identity = $identity;
        $this->mqtt->reauth->add(1, ['result' => 'success']);
        Span::add('mqtt.result', 'reauthenticated');

        return Auth::success($auth->method);
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $suback = new Suback();

        // The connection's user must still be valid: re-check that it was not blocked or removed
        // since CONNECT. If it is no longer permitted, refuse every filter before any topic gating.
        $authorizer = $this->container->get('authorizer');
        if ($authorizer !== null && !$authorizer($connection->identity)) {
            foreach ($subscribe->filters() as $filter) {
                $suback->deny();
            }

            return $suback;
        }

        $projectDB = $this->getProjectDB($connection->prefix);
        $userId = $connection->identity['userId'] ?? '';

        // Resolve exact topic names to their documents: needed to cap QoS, enable replay, and key the
        // subscriber-list check on an ordinary topic's id.
        $names = [];
        foreach ($subscribe->filters() as $filter) {
            if ($this->isWildcard($filter->topic) || $this->deniesUserTopic($filter->topic, $userId)) {
                continue;
            }
            $names[$filter->topic] = true;
        }

        $authorization = new Authorization();
        $projectDB->setAuthorization($authorization);

        $topicsByName = [];
        if ($names !== []) {
            $documents = $authorization->skip(fn () => $projectDB->find('topics', [
                Query::equal('name', array_keys($names)),
                Query::limit(\count($names)),
            ]));
            foreach ($documents as $document) {
                $topicsByName[$document->getAttribute('name')] ??= $document;
            }
        }

        $grantedTopics = [];

        foreach ($subscribe->filters() as $filter) {
            Span::add('mqtt.topic', $filter->topic);

            // The reserved users/ namespace: refuse wildcards and other users' topics; an owned
            // users/<id> falls through and is served like any exact topic name below.
            if ($this->deniesUserTopic($filter->topic, $userId)) {
                $suback->deny();
                continue;
            }

            if ($this->isReservedUserTopic($filter->topic)) {
                // Authorised by ownership; its document may not exist yet (auto-provisioned on publish).
                $document = $topicsByName[$filter->topic] ?? null;
            } else {
                // Ordinary topic: a wildcard cannot be matched against one topic's list, and an exact
                // topic must be one the user is a subscriber of.
                $document = $this->isWildcard($filter->topic) ? null : ($topicsByName[$filter->topic] ?? null);

                if ($document === null || !$this->isSubscribed($projectDB, $authorization, $userId, (string) $document->getSequence())) {
                    $suback->deny();
                    continue;
                }
            }

            $topicQos = $document?->getAttribute('qos');
            $grantedQos = \min($filter->qos, $topicQos === null ? Packet::QOS_1 : (int) $topicQos);
            $suback->grant($grantedQos);

            // Offline replay needs an exact topic; an owned user topic with no document yet is live only.
            if ($document !== null) {
                $grantedTopics[$filter->topic] = [$document, $grantedQos];
            }
        }

        if ($grantedTopics !== []) {
            $this->replayBacklog($connection, $projectDB, $grantedTopics);
        }

        return $suback;
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        // The broker has already dropped the filters from its subscription index.
        $unsuback = new Unsuback();
        foreach ($unsubscribe->filters() as $filter) {
            $unsuback->success();
        }

        return $unsuback;
    }

    /**
     * Appwrite is server -> client push: clients never PUBLISH, so an inbound one is ignored.
     * Server-initiated fan-out goes through deliver() instead.
     *
     * @param iterable<array{0: Connection, 1: int}> $subscribers
     */
    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
    }

    /**
     * Fan a message out to a topic's local subscribers, each at the QoS granted to that
     * subscription (clamped by the message QoS). Returns the number delivered.
     *
     * A reserved users/<id> topic reaches only that user's connections, whatever filter matched:
     * the subscribe gate is the first line, this holds even for a subscription it did not catch.
     */
    public function deliver(Server $server, string $projectId, string $topic, string $message, int $qos, int $sequence, float $publishedAt = 0.0): int
    {
        $segments = \explode('/', $topic);
        $owner = \count($segments) === 2 && $segments[0] === self::USER_TOPIC_PREFIX ? $segments[1] : null;

        // publish (worker) -> deliver (broker) latency; clamped as the two clocks may differ slightly.
        if ($publishedAt > 0.0) {
            $this->mqtt->deliveryLatency->record(\max(0.0, \microtime(true) - $publishedAt));
        }

        $delivered = 0;

        foreach ($server->subscribers($projectId, $topic) as [$connection, $grantedQos]) {
            if ($owner !== null && ($connection->identity['userId'] ?? '') !== $owner) {
                continue;
            }

            $deliveryQos = \min($qos, $grantedQos);
            $connection->publish($topic, $message, qos: $deliveryQos, sequence: $sequence);
            $this->mqtt->messagesDelivered->add(1, ['qos' => $deliveryQos]);
            $delivered++;
        }

        $this->mqtt->recordDeliveries($projectId, $delivered);

        return $delivered;
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
        $delivery = $connection->acknowledge($puback->packetId);
        if ($delivery === null) {
            return;
        }

        Span::add('mqtt.topic', $delivery['topic']);
        Span::add('mqtt.sequence', $delivery['sequence']);
        $this->mqtt->messagesAcked->add(1);
        $this->mqtt->pubacksReceived->add(1);

        $cache = ($this->getCache)();
        $key = $this->cursorKey($connection);
        $topic = $delivery['topic'];
        $cursor = (int) $delivery['cursor'];

        $current = $cache->loadMany($key, self::CURSOR_TTL, [$topic]);
        $cursor = \max($cursor, $connection->cursor($topic) ?? $cursor);
        if ($cursor > (int) ($current[$topic]['sequence'] ?? -1)) {
            $binding = $current[$topic]['topic'] ?? $this->topicSequence($this->getProjectDB($connection->prefix), $topic);
            $cache->saveMany($key, [$topic => ['sequence' => $cursor, 'topic' => $binding]], self::CURSOR_TTL);
        }
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
        $this->untrack($connection);

        // The broker already removed the connection, its subscriptions and keep-alive slot, and owns
        // the connections.active gauge. Record the lifetime of accepted sessions (identity set in
        // onConnect) only, which also have an openedAt.
        if (($connection->identity['userId'] ?? '') !== '') {
            $this->mqtt->connectionDuration->record(microtime(true) - $connection->openedAt);
        }
    }

    /** Disconnect the user's connections if they lost access; returns how many were disconnected. */
    public function revoke(string $projectId, string $userId): int
    {
        if ($this->connecting > 0) {
            $this->revoked[$projectId][$userId] = ++$this->revocations;
        }

        if ($this->connectionsOf($projectId, $userId) === []) {
            return 0;
        }

        if ($this->authorized(['projectId' => $projectId, 'userId' => $userId])) {
            return 0;
        }

        $connections = $this->connectionsOf($projectId, $userId);
        $reason = (new Exception(Exception::USER_BLOCKED))->getMessage();

        foreach ($connections as $connection) {
            $this->untrack($connection);
            $connection->disconnect(Disconnect::NOT_AUTHORIZED, $reason);
        }

        return \count($connections);
    }

    /** @param array<string, mixed> $identity */
    private function authorized(array $identity): bool
    {
        try {
            $authorizer = $this->container->get('authorizer');

            return (bool) $authorizer($identity);
        } catch (\Throwable $error) {
            Console::error('MQTT authorizer failed: ' . $error->getMessage());

            return false;
        }
    }

    /** @return array<int, Connection> fd => connection */
    private function connectionsOf(string $projectId, string $userId): array
    {
        return $this->connections[$projectId][$userId] ?? [];
    }

    private function track(Connection $connection): void
    {
        if ($connection->closed) {
            return;
        }

        $projectId = (string) ($connection->identity['projectId'] ?? '');
        $userId = (string) ($connection->identity['userId'] ?? '');
        if ($userId === '') {
            return;
        }

        $this->connections[$projectId][$userId][$connection->fd] = $connection;
    }

    private function untrack(Connection $connection): void
    {
        $projectId = (string) ($connection->identity['projectId'] ?? '');
        $userId = (string) ($connection->identity['userId'] ?? '');

        unset($this->connections[$projectId][$userId][$connection->fd]);

        if (($this->connections[$projectId][$userId] ?? null) === []) {
            unset($this->connections[$projectId][$userId]);
        }

        if (($this->connections[$projectId] ?? null) === []) {
            unset($this->connections[$projectId]);
        }
    }

    /** Whether a subscription filter is an MQTT wildcard (single-level + or multi-level #). */
    private function isWildcard(string $topic): bool
    {
        return \str_contains($topic, '+') || \str_contains($topic, '#');
    }

    /**
     * Whether a filter that could reach the reserved users/ namespace must be refused: a wildcard
     * first level (# or +, which the broker matches against users/<id> like any other first level),
     * any wildcard under users/ (so it can't span other users), or an exact users/<id> that is not
     * the caller's own. An owned users/<id> is allowed (served like any exact topic), and a deeper
     * users/<id>/… path is an ordinary topic, not a reserved one.
     */
    private function deniesUserTopic(string $topic, string $userId): bool
    {
        $first = \explode('/', $topic, 2)[0];
        if ($first === '#' || $first === '+') {
            return true;
        }

        if (!\str_starts_with($topic, self::USER_TOPIC_PREFIX . '/')) {
            return false;
        }

        if ($this->isWildcard($topic)) {
            return true;
        }

        $segments = \explode('/', $topic);

        return \count($segments) === 2 && $segments[1] !== $userId;
    }

    /**
     * Whether a topic is an exact reserved per-user topic (users/<id>), authorised by ownership rather
     * than the subscriber list. A deeper users/<id>/… path is ordinary, matching deliver()'s carve-out.
     */
    private function isReservedUserTopic(string $topic): bool
    {
        $segments = \explode('/', $topic);

        return \count($segments) === 2 && $segments[0] === self::USER_TOPIC_PREFIX;
    }

    /** Whether the user is entered as a subscriber of the topic (via any of their targets). */
    private function isSubscribed(Database $projectDB, Authorization $authorization, string $userId, string $topicInternalId): bool
    {
        if ($userId === '') {
            return false;
        }

        return !$authorization->skip(fn () => $projectDB->findOne('subscribers', [
            Query::equal('topicInternalId', [$topicInternalId]),
            Query::equal('userId', [$userId]),
        ]))->isEmpty();
    }

    /**
     * Replay the offline backlog for freshly granted QoS 1 topics and persist each topic's cursor.
     * Each topic resumes at its persisted cursor, then the newest messages after it (up to the
     * plan's depth, above the newest expired one) are re-delivered oldest first via the connection,
     * DUP flagged and tagged with their durable sequence so a later PUBACK maps back. The cursor is
     * raised to just below the oldest replayed message (or to the tail when nothing is replayable),
     * so messages left out of a truncated replay, expired or pruned cannot pin it. Each topic
     * persists the higher of that floor and the connection's cursor, so a PUBACK processed while a
     * ledger query yielded is never undone.
     *
     * @param array<string, array{0: Document, 1: int}> $grantedTopics filter => [document, grantedQos]
     */
    private function replayBacklog(Connection $connection, Database $projectDB, array $grantedTopics): void
    {
        $cache = ($this->getCache)();
        $key = $this->cursorKey($connection);
        $maxDepth = \max(1, ($this->planForUser)(new Document(['$id' => $connection->prefix]), $connection->identity['userId'] ?? ''));

        if ($connection->cleanStart) {
            $cache->purge($key);
        }

        $cursors = $cache->loadMany($key, self::CURSOR_TTL);
        $floors = [];
        $topics = [];
        $providerExpiry = null;
        $providerResolved = false;

        foreach ($grantedTopics as $filter => [$document, $grantedQos]) {
            $tail = (int) $document->getAttribute('sequence', 0);
            $topicSequence = (string) $document->getSequence();
            $stored = $cursors[$filter] ?? null;
            $from = (int) ($stored['sequence'] ?? $tail);
            // A cursor bound to another topic of the same name, or above this topic's tail (a topic's sequence
            // only grows), was left by an earlier topic: all of this topic's ledger is new to the device.
            if ($stored !== null && (($stored['topic'] ?? $topicSequence) !== $topicSequence || $from > $tail)) {
                $from = 0;
            }
            $topics[$filter] = $topicSequence;
            if ($connection->resumed($filter)) {
                $from = \max($from, (int) $connection->cursor($filter));
            }
            $floors[$filter] = $from;

            if ($grantedQos < 1) {
                continue;
            }

            if ($tail <= $from) {
                $connection->raise($filter, $from);
                continue;
            }

            $this->mqtt->replayBacklog->record($tail - $from);
            $connection->resume($filter, $from);

            $topicExpiry = $document->getAttribute('expiry');
            if ($topicExpiry === null && !$providerResolved) {
                $providerExpiry = $this->providerExpiry($projectDB);
                $providerResolved = true;
            }
            $expiry = $topicExpiry === null ? $providerExpiry : (int) $topicExpiry;

            $messages = $this->backlog($projectDB, $document, \max($from, $tail - $maxDepth), $maxDepth, $expiry);

            $floor = $messages === [] ? $tail : (int) $messages[0]->getAttribute('sequence') - 1;
            $connection->raise($filter, $floor);
            $floors[$filter] = $floor;

            $replayed = 0;
            foreach ($messages as $message) {
                $stored = $message->getAttribute('data');
                $data = \is_string($stored) ? $stored : (string) json_encode($stored);
                $connection->publish($filter, $data, qos: 1, dup: true, sequence: (int) $message->getAttribute('sequence'));
                $this->mqtt->messagesDelivered->add(1, ['qos' => 1]);
                $replayed++;
            }

            // Offline-replay re-deliveries are real deliveries: account for them in per-project usage
            // too, so a device catching up after reconnect is not undercounted.
            $this->mqtt->recordDeliveries($connection->prefix, $replayed);
        }

        $persist = [];
        foreach ($floors as $filter => $floor) {
            $persist[$filter] = ['sequence' => \max($floor, $connection->cursor($filter) ?? $floor), 'topic' => $topics[$filter]];
        }

        if ($persist !== []) {
            $cache->saveMany($key, $persist, self::CURSOR_TTL);
        }
    }

    /**
     * The newest ledger messages of a topic above $after, at most $maxDepth, oldest first, cut below
     * the newest one written more than $expiry seconds ago. The cut is a prefix, not a per-message
     * filter: $createdAt is stamped by each writer's clock, so an expired message can sit between
     * fresh ones, and skipping only it would leave a gap that pins the cursor. A null expiry keeps
     * the whole window; zero keeps none.
     *
     * @return array<Document>
     */
    private function backlog(Database $projectDB, Document $topic, int $after, int $maxDepth, ?int $expiry): array
    {
        if ($expiry !== null && $expiry <= 0) {
            return [];
        }

        $newestFirst = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('pushLedger', [
            Query::equal('topic', [$topic->getId()]),
            Query::greaterThan('sequence', $after),
            Query::orderDesc('sequence'),
            Query::orderDesc('$sequence'),
            Query::limit($maxDepth),
        ]));

        if ($expiry === null) {
            return \array_reverse($newestFirst);
        }

        $cutoff = ($this->clock)()->sub(new \DateInterval('PT' . $expiry . 'S'));
        $fresh = [];
        foreach ($newestFirst as $message) {
            if (new \DateTimeImmutable((string) $message->getCreatedAt()) < $cutoff) {
                break;
            }
            $fresh[] = $message;
        }

        return \array_reverse($fresh);
    }

    /**
     * The expiry a topic without its own inherits: the lowest expiry set on the project's enabled
     * Appwrite providers, so a message is never kept longer than any of them allows. Null when
     * none sets one.
     */
    /** The internal sequence of the topic named $name, which a cursor is bound to; null when no such topic exists. */
    private function topicSequence(Database $projectDB, string $name): ?string
    {
        $topic = $projectDB->getAuthorization()->skip(fn () => $projectDB->findOne('topics', [
            Query::equal('name', [$name]),
        ]));

        return $topic->isEmpty() ? null : (string) $topic->getSequence();
    }

    private function providerExpiry(Database $projectDB): ?int
    {
        $providers = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('providers', [
            Query::equal('provider', [MESSAGE_PROVIDER_APPWRITE]),
            Query::equal('enabled', [true]),
            Query::select(['options']),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        $expiries = [];
        foreach ($providers as $provider) {
            $expiry = $provider->getAttribute('options', [])['expiry'] ?? null;
            if ($expiry !== null) {
                $expiries[] = (int) $expiry;
            }
        }

        return $expiries === [] ? null : \min($expiries);
    }

    private function cursorKey(Connection $connection): string
    {
        return 'push:cursor:' . $connection->prefix
            . ':' . ($connection->identity['userId'] ?? '')
            . ':' . $connection->getClientId();
    }

    private function getProjectDB(string $projectId): Database
    {
        $getConsoleDB = $this->container->get('getConsoleDB');
        $getProjectDB = $this->container->get('getProjectDB');

        $consoleDB = $getConsoleDB();
        $authorization = new Authorization();
        $consoleDB->setAuthorization($authorization);
        $project = $authorization->skip(fn () => $consoleDB->getDocument('projects', $projectId));

        return $getProjectDB($project);
    }
}
