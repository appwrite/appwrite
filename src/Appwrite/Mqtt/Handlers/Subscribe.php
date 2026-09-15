<?php

namespace Appwrite\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Mqtt\Dispatcher;
use Appwrite\Mqtt\Response;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V3;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Platform\Action;
use Utopia\Span\Span;

class Subscribe extends Action
{
    public function __construct()
    {
        $this
            ->desc('Subscribe to topic filters, enforcing the ACL')
            ->label(Dispatcher::LABEL_TYPE, Packet::SUBSCRIBE)
            ->inject('authorizer')
            ->inject('mqtt')
            ->inject('connection')
            ->inject('packet')
            ->inject('response')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(array<string, string>, string): bool)|null $authorizer
     */
    public function action(?callable $authorizer, Mqtt $mqtt, Connection $connection, Packet $packet, Response $response): void
    {
        $body = $packet->body;
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;

        // MQTT 5.0 carries a property block before the filters; skip it to reach them.
        if ($connection->protocol >= 5) {
            $offset = Properties::skip($body, $offset);
        }

        $identity = $connection->identity;

        $denied = $connection->protocol >= 5 ? V5::REASON_NOT_AUTHORIZED : V3::SUBSCRIBE_FAILURE;

        $consoleDatabase = getConsoleDB();
        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $connection->prefix));
        $projectDB = getProjectDB($project);

        // The subscriber's roles, resolved once, to enforce each topic's subscribe ACL below.
        $authorization = $projectDB->getAuthorization();
        /** @var User $user */
        $user = $authorization->skip(fn () => $projectDB->getDocument('users', $connection->identity['userId'] ?? ''));
        $roles = $user->getRoles($authorization);

        // Parse every (filter, requested QoS) in order; the SUBACK carries one reason code
        // per filter in this order. Bits 0-1 of the subscription options byte are the max QoS.
        $filters = [];
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $filters[] = [$filter, isset($body[$offset]) ? \ord($body[$offset]) & 0x03 : 0];
            $offset += 1;
        }

        $allowed = [];
        foreach ($filters as [$filter]) {
            if (!isset($allowed[$filter]) && ($authorizer === null || $authorizer($identity, $filter))) {
                $allowed[$filter] = true;
            }
        }

        $topicsById = [];
        if ($allowed !== []) {
            $documents = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('topics', [
                Query::equal('$id', array_keys($allowed)),
                Query::limit(\count($allowed)),
            ]));
            foreach ($documents as $document) {
                $topicsById[$document->getId()] = $document;
            }
        }

        $granted = '';
        $topicDocuments = []; // filter => topic document, reused for replay below
        $grantedQosByTopic = []; // filter => granted QoS, so replay honours it below
        foreach ($filters as [$filter, $requestedQos]) {
            Span::add('mqtt.topic', $filter);

            if (!isset($allowed[$filter])) {
                $granted .= chr($denied);
                $mqtt->subscriptions->add(1, ['result' => 'denied']);
                continue;
            }

            // A filter is granted only if it maps to an existing project topic.
            $topicDocument = $topicsById[$filter] ?? null;
            if ($topicDocument === null) {
                $granted .= chr($denied);
                $mqtt->subscriptions->add(1, ['result' => 'unknown']);
                continue;
            }

            // The topic's subscribe roles gate who may subscribe.
            if (!$this->authorizedForTopic($topicDocument->getAttribute('subscribe', []) ?? [], $roles)) {
                $granted .= chr($denied);
                $mqtt->subscriptions->add(1, ['result' => 'forbidden']);
                continue;
            }

            // Requested max QoS, capped by the topic's configured QoS (null = no cap, broker max 1).
            $topicQos = $topicDocument->getAttribute('qos');
            $grantedQos = min($requestedQos, $topicQos === null ? Packet::QOS_1 : (int) $topicQos);

            $mqtt->subscribe($connection->prefix, $connection->fd, '', [], [$filter], [], $grantedQos);
            $granted .= chr($grantedQos);
            $mqtt->subscriptions->add(1, ['result' => 'granted']);

            $topicDocuments[$filter] = $topicDocument;
            $grantedQosByTopic[$filter] = $grantedQos;
        }

        $response->send(
            $connection->protocol >= 5
                ? V5::suback($packetId, $granted)
                : V3::suback($packetId, $granted),
        );

        if ($topicDocuments === []) {
            return;
        }

        $topics = array_keys($topicDocuments);

        $expiry = 0;
        foreach ($topicDocuments as $topicDocument) {
            $expiry = max($expiry, (int) $topicDocument->getAttribute('expiry', 0));
        }
        if ($expiry <= 0) {
            $expiry = 3600;
        }

        // Replay depth comes from the user's org plan (self-hosted default; see getPlanForUser)
        $maxDepth = max(1, getPlanForUser($project, $connection->identity['userId'] ?? ''));

        $cache = getCache();

        $cursorKey = 'appwrite:push:cursor:' . $connection->prefix . ':' . $connection->identity['userId'] . ':' . $connection->getClientId();

        // A clean-start session discards any persisted cursor before resuming.
        if ($connection->cleanStart) {
            $cache->purge($cursorKey);
        }

        $cursors = $cache->loadMany($cursorKey, $expiry);
        $known = array_keys($cursors);
        $newTopics = array_values(array_diff($topics, $known));
        $resumeTopics = array_values(array_intersect($topics, $known));

        // Current tail (topics.sequence) per subscribed topic: the seed for new topics and
        // the upper bound for replay. Reuses the documents fetched during the grant loop.
        $tails = [];
        foreach ($topicDocuments as $topic => $topicDocument) {
            $tails[$topic] = (int) $topicDocument->getAttribute('sequence', 0);
        }

        $persist = [];
        foreach ($newTopics as $topic) {
            $persist[$topic] = ['sequence' => $tails[$topic] ?? 0];
        }
        foreach ($resumeTopics as $topic) {
            $persist[$topic] = $cursors[$topic];
        }

        foreach ($resumeTopics as $topic) {
            if (($grantedQosByTopic[$topic] ?? Packet::QOS_1) < Packet::QOS_1) {
                continue;
            }

            $from = (int) ($cursors[$topic]['sequence'] ?? 0);
            $tail = $tails[$topic] ?? $from;
            if ($tail <= $from) {
                continue;
            }

            $start = max($from + 1, $tail - $maxDepth + 1);
            $messages = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('appwritePushLedger', [
                Query::equal('topic', [$topic]),
                Query::greaterThanEqual('sequence', $start),
                Query::orderAsc('sequence'),
                Query::limit($maxDepth),
            ]));

            foreach ($messages as $message) {
                $packetId = $connection->nextPacketId();
                // The ledger `data` (json filter) already holds the encoded envelope
                // string the live path publishes; re-encoding it would double-encode.
                $stored = $message->getAttribute('data');
                $data = \is_string($stored) ? $stored : (string) json_encode($stored);
                $publish = $connection->protocol >= 5
                    ? V5::publish($topic, $data, Packet::QOS_1, $packetId, dup: true)
                    : V3::publish($topic, $data, Packet::QOS_1, $packetId, dup: true);
                $response->send($publish);
                $connection->track($packetId, $topic, (int) $message->getAttribute('sequence'));
            }
        }

        if ($persist !== []) {
            $cache->saveMany($cursorKey, $persist, $expiry);
        }
    }

    /**
     * Whether the subscriber's roles satisfy a topic's subscribe roles. No configured roles
     * means the topic is open to everyone, as does an explicit `any` role.
     *
     * @param array<int, string> $topicRoles the topic's `subscribe` roles
     * @param array<int, string> $userRoles  the subscriber's resolved roles
     */
    private function authorizedForTopic(array $topicRoles, array $userRoles): bool
    {
        if ($topicRoles === []) {
            return true;
        }

        if (\in_array(Role::any()->toString(), $topicRoles, true)) {
            return true;
        }

        return \array_intersect($topicRoles, $userRoles) !== [];
    }
}
