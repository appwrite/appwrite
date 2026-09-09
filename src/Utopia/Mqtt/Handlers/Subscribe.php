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

        $subId = '';
        if ($connection->protocol >= 5) {
            [$properties, $offset] = Properties::parse($body, $offset);
            $subId = (string) ($properties->user()['subId'] ?? '');
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

            $mqtt->subscribe($connection->projectId, $connection->fd, $subId ?: $filter, [], [$filter]);
            $granted .= chr(Packet::QOS_1); // granted max QoS 1
            $mqtt->metrics->subscriptions->add(1, ['result' => 'granted']);

            // TODO: if we can save directly in the same cache key only
            $key = 'mqtt:sub:' . $connection->projectId . ':' . $connection->identity['userId'] . ':' . $connection->getClientId() . ':' . $filter;
            getCache()->save($key, ['subId' => $subId, 'qos' => Packet::QOS_1]);
            $topics[] = $filter;
        }

        $reply(
            $connection->protocol >= 5
                ? V5::suback($packetId, $granted)
                : V3::suback($packetId, $granted),
            false,
        );

        // session replay
        // TODO: update the ttl + bulk fetch + bulk upload to the cache to reduce the network calls
        // same for the database below
        $consoleDatabase = getConsoleDB();
        $project = $consoleDatabase->getAuthorization()->skip(fn () => $consoleDatabase->getDocument('projects', $connection->projectId));
        $projectDB = getProjectDB($project);

        $cache = getCache();
        foreach ($topics as $topic) {
            $lastMessageCursorKey =  'mqtt:cursor:' . $connection->projectId . ':' . $connection->identity['userId'] . ':' . $connection->getClientId() . ':' . $topic;
            $payload = $cache->load($lastMessageCursorKey, 3600);

            $topicDocument = $projectDB->getAuthorization()->skip(fn () => $projectDB->findOne('messages', [Query::containsAny('topics', [$topic]), Query::orderDesc('$sequence'), Query::orderDesc('data')]));
            if($topicDocument->isEmpty()) continue;
            if(empty($topicDocument->getAttribute('data'))) continue;

            $sequence = $topicDocument?->getAttribute('$sequence') ?? -1;

            if ($payload === false) {
                // Newly connected device: register at the current tail so it starts from now.
                $cache->save($lastMessageCursorKey, ['sequence' => $sequence]);
                continue;
            }

            $lastSubscriberSequence = max(-1, $payload['sequence'] ?? -1);

            if($lastSubscriberSequence === $sequence){
                continue;
            }

            // TODO: fetch the last global message cursor then calculate the depth and send
            $packetId = $connection->nextPacketId();
            $publish = $connection->protocol >= 5
                ? V5::publish($topic, json_encode($topicDocument->getAttribute('data')), Packet::QOS_1, $packetId, dup: true)
                : V3::publish($topic, json_encode($topicDocument->getAttribute('data')), Packet::QOS_1, $packetId, dup: true);
            $reply($publish, false);
            $connection->track($packetId, $topic, $sequence);
        }
    }
}
