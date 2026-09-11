<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Database\Query;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
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
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param (callable(array<string, string>, string): bool)|null $authorizer
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(?callable $authorizer, Mqtt $mqtt, Connection $connection, Packet $packet, callable $reply): void
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

        // A denied filter answers with each version's own failure marker; a granted one
        // with max QoS 1 (0x01), which is a granted-QoS byte in 3.1.1 and a Success
        // reason code in 5.0.
        $denied = $connection->protocol >= 5 ? V5::REASON_NOT_AUTHORIZED : V3::SUBSCRIBE_FAILURE;

        $granted = '';
        $topics = [];
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $offset += 1; // subscription options byte
            Span::add('mqtt.topic', $filter);

            // ACL: an unauthorized filter is refused and never stored.
            if ($authorizer !== null && !$authorizer($identity, $filter)) {
                $granted .= chr($denied);
                $mqtt->metrics->subscriptions->add(1, ['result' => 'denied']);
                Span::add('mqtt.result', 'denied');
                continue;
            }

            $mqtt->subscribe($connection->projectId, $connection->fd, '', [], [$filter]);
            $granted .= chr(Packet::QOS_1); // granted max QoS 1
            $mqtt->metrics->subscriptions->add(1, ['result' => 'granted']);

            $topics[] = $filter;
        }

        $reply(
            $connection->protocol >= 5
                ? V5::suback($packetId, $granted)
                : V3::suback($packetId, $granted),
            false,
        );

        if ($topics === []) {
            return;
        }

        // TODO: expiry should track the plan
        $expiry = 3600;
        $maxDepth = 5;

        $consoleDatabase = getConsoleDB();
        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $connection->projectId));
        $projectDB = getProjectDB($project);
        $cache = getCache();

        $cursorKey = 'mqtt:cursor:' . $connection->projectId . ':' . $connection->identity['userId'] . ':' . $connection->getClientId();

        // A clean-start session discards any persisted cursor before resuming.
        if ($connection->cleanStart) {
            $cache->purge($cursorKey);
        }

        $cursors = $cache->loadMany($cursorKey, $expiry);
        $known = array_keys($cursors);
        $newTopics = array_values(array_diff($topics, $known));
        $resumeTopics = array_values(array_intersect($topics, $known));

        // Current tail (topics.sequence) per subscribed topic: the seed for new topics and
        // the upper bound for replay. getDocument is cached, so repeated reads are cheap.
        $tails = [];
        foreach ($topics as $topic) {
            $topicDocument = $projectDB->getAuthorization()->skip(fn () => $projectDB->getDocument('topics', $topic));
            if (!$topicDocument->isEmpty()) {
                $tails[$topic] = (int) $topicDocument->getAttribute('sequence', 0);
            }
        }

        $persist = [];
        foreach ($newTopics as $topic) {
            $persist[$topic] = ['sequence' => $tails[$topic] ?? 0];
        }
        foreach ($resumeTopics as $topic) {
            $persist[$topic] = $cursors[$topic];
        }

        foreach ($resumeTopics as $topic) {
            $from = (int) ($cursors[$topic]['sequence'] ?? 0);
            $tail = $tails[$topic] ?? $from;
            if ($tail <= $from) {
                continue;
            }

            $start = max($from + 1, $tail - $maxDepth + 1);
            $messages = $projectDB->getAuthorization()->skip(fn () => $projectDB->find('appwrite_push_ledger', [
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
                $reply($publish, false);
                $connection->track($packetId, $topic, (int) $message->getAttribute('sequence'));
            }
        }

        if ($persist !== []) {
            $cache->saveMany($cursorKey, $persist, $expiry);
        }
    }
}
