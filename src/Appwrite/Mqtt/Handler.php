<?php

namespace Appwrite\Mqtt;

use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as TimeLimitRedis;
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
    private const REPLAY_TTL = 3600;

    public function __construct(
        private readonly Container $container,
        private readonly Mqtt $mqtt,
        private readonly \Closure $getCache,
        private readonly \Closure $planForUser,
    ) {
    }

    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        $authMethod = $connect->authMethod ?? '';

        $connection->prefix = $connect->userProperties()['projectId'] ?? '';
        Span::add('project.id', $connection->prefix);
        Span::add('mqtt.clean_start', $connect->cleanStart);
        Span::add('mqtt.auth_method', $authMethod);

        $authenticator = $this->container->get('authenticator');

        $start = microtime(true);
        $identity = $authenticator($connection->prefix, $authMethod, $connect->authData ?? '');
        $this->mqtt->authDuration->record(microtime(true) - $start);

        if ($identity === []) {
            Span::add('mqtt.result', 'rejected');
            return $this->refuseConnect(Connack::NOT_AUTHORIZED, Exception::USER_UNAUTHORIZED);
        }

        $connection->identity = $identity;
        Span::add('user.id', $identity['userId'] ?? '');

        // Rate-limit CONNECT per user (keyed on userId until the infra surfaces client IP).
        if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
            $getRedis = $this->container->get('getRedis');
            $timeLimit = new TimeLimitRedis('mqtt:connect:{userId}', 128, 60, $getRedis());
            $timeLimit->setParam('{userId}', $identity['userId'] ?? '');

            if ((new Abuse($timeLimit))->check()) {
                Span::add('mqtt.result', 'abuse');
                return $this->refuseConnect(Connack::QUOTA_EXCEEDED, Exception::GENERAL_RATE_LIMIT_EXCEEDED);
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

        return Connack::accept();
    }

    /**
     * Refuse a CONNECT with an MQTT reason code and, on MQTT 5.0, the matching Appwrite error
     * message as the Reason String (property 0x1F) so clients learn why — the same messages the
     * realtime endpoint returns. 3.1.1 clients only get the reason code; the string is dropped.
     */
    private function refuseConnect(int $reasonCode, string $error): Connack
    {
        $properties = new Properties();
        $properties->add(new Property(Property::REASON_STRING, (new Exception($error))->getMessage()));

        return Connack::refuse($reasonCode, $properties);
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

        // Topic selection is open, but the connection's user must still be valid: re-check that it
        // was not blocked or removed since CONNECT. If it is no longer permitted, refuse every filter.
        $authorizer = $this->container->get('authorizer');
        if ($authorizer !== null && !$authorizer($connection->identity)) {
            foreach ($subscribe->filters() as $filter) {
                $suback->deny();
            }

            return $suback;
        }

        $projectDB = $this->getProjectDB($connection->prefix);

        // Subscription is open: a permitted connection may subscribe to any topic or wildcard. The
        // topic document is consulted only to cap the QoS and to enable offline replay for an exact
        // topic name — never to allow or deny the subscription.
        $names = [];
        foreach ($subscribe->filters() as $filter) {
            if (!$this->isWildcard($filter->topic)) {
                $names[$filter->topic] = true;
            }
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

            $document = $this->isWildcard($filter->topic) ? null : ($topicsByName[$filter->topic] ?? null);

            $topicQos = $document?->getAttribute('qos');
            $grantedQos = min($filter->qos, $topicQos === null ? Packet::QOS_1 : (int) $topicQos);
            $suback->grant($grantedQos);

            // Offline replay needs an exact topic (its ledger and cursor); a wildcard or an unknown
            // topic is delivered live only.
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
     */
    public function deliver(Server $server, string $projectId, string $topic, string $message, int $qos, int $sequence): int
    {
        $delivered = 0;

        foreach ($server->subscribers($projectId, $topic) as [$connection, $grantedQos]) {
            $deliveryQos = min($qos, $grantedQos);
            $connection->publish($topic, $message, qos: $deliveryQos, sequence: $sequence);
            $this->mqtt->messagesDelivered->add(1, ['qos' => $deliveryQos]);
            $delivered++;
        }

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

        $current = $cache->loadMany($key, self::REPLAY_TTL, [$topic]);
        if ($cursor > (int) ($current[$topic]['sequence'] ?? -1)) {
            $cache->saveMany($key, [$topic => ['sequence' => $cursor]], 0);
        }
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
        // The broker already removed the connection, its subscriptions and keep-alive slot, and
        // records the active-connections gauge itself. Record the connection lifetime for
        // accepted sessions.
        if (($connection->identity['userId'] ?? '') !== '') {
            $this->mqtt->connectionDuration->record(microtime(true) - $connection->openedAt);
        }
    }

    /** Whether a subscription filter is an MQTT wildcard (single-level + or multi-level #). */
    private function isWildcard(string $topic): bool
    {
        return \str_contains($topic, '+') || \str_contains($topic, '#');
    }

    /**
     * Replay the offline backlog for freshly granted QoS 1 topics: resume each topic's cursor,
     * then re-deliver stored messages via the connection (DUP flagged), tagging each with its
     * durable sequence so a later PUBACK maps back.
     *
     * @param array<string, array{0: Document, 1: int}> $grantedTopics filter => [document, grantedQos]
     */
    private function replayBacklog(Connection $connection, Database $projectDB, array $grantedTopics): void
    {
        $cache = ($this->getCache)();
        $key = $this->cursorKey($connection);
        $maxDepth = max(1, ($this->planForUser)(new Document(['$id' => $connection->prefix]), $connection->identity['userId'] ?? ''));

        $expiry = 0;
        foreach ($grantedTopics as [$document]) {
            $expiry = max($expiry, (int) $document->getAttribute('expiry', 0));
        }
        if ($expiry <= 0) {
            $expiry = self::REPLAY_TTL;
        }

        if ($connection->cleanStart) {
            $cache->purge($key);
        }

        $cursors = $cache->loadMany($key, $expiry);
        $persist = [];

        foreach ($grantedTopics as $filter => [$document, $grantedQos]) {
            $tail = (int) $document->getAttribute('sequence', 0);
            $from = (int) ($cursors[$filter]['sequence'] ?? $tail);
            $persist[$filter] = ['sequence' => $from];

            if ($grantedQos < 1 || $tail <= $from) {
                continue;
            }

            $connection->resume($filter, $from);

            $start = max($from + 1, $tail - $maxDepth + 1);
            $messages = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('pushLedger', [
                Query::equal('topic', [$document->getId()]),
                Query::greaterThanEqual('sequence', $start),
                Query::orderAsc('sequence'),
                Query::limit($maxDepth),
            ]));

            foreach ($messages as $message) {
                $stored = $message->getAttribute('data');
                $data = \is_string($stored) ? $stored : (string) json_encode($stored);
                $connection->publish($filter, $data, qos: 1, dup: true, sequence: (int) $message->getAttribute('sequence'));
            }
        }

        if ($persist !== []) {
            $cache->saveMany($key, $persist, $expiry);
        }
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
