<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V5;
use Utopia\Platform\Action;
use Utopia\Span\Span;

/**
 * A subscriber's PUBACK for a QoS 1 message the broker delivered. This is the only
 * event that advances that subscriber's cursor: the ack proves the subscriber holds
 * that sequence, so replay on reconnect can skip it. A cursor never advances on
 * publish, only here, which is how an offline client keeps its backlog.
 */
class Puback extends Action
{
    public function __construct()
    {
        $this
            ->desc('Acknowledge a delivered QoS 1 message and advance the subscriber cursor')
            ->label(Dispatcher::LABEL_TYPE, Packet::PUBACK)
            ->inject('mqtt')
            ->inject('connection')
            ->inject('packet')
            ->inject('reply')
            ->callback($this->action(...));
    }

    public function action(Mqtt $mqtt, Connection $connection, Packet $packet, callable $reply): void
    {
        $body = $packet->body;

        if (\strlen($body) < 2) {
            return;
        }

        $connectionMetdata = $mqtt->getConnection($connection->fd);
        if ($connectionMetdata['userId'] !== $connection->identity['userId'] || $connectionMetdata['projectId'] !== $connection->projectId) {
            $reply(V5::disconnect(V5::REASON_NOT_AUTHORIZED), true);
            return;
        }

        // PUBACK opens with the two-byte packet id in both versions.
        [$packetId] = Packet::readInt16($body, 0);

        $delivery = $connection->acknowledge($packetId);
        if ($delivery === null) {
            return;
        }

        Span::add('mqtt.topic', $delivery['topic']);
        Span::add('mqtt.sequence', $delivery['sequence']);
        $mqtt->metrics->messagesAcked->add(1);

        // Save how far the client has caught up (the "cursor" from acknowledge), not the
        // sequence it just acked — so an out-of-order ack can't skip a still-unacked
        // message. Only ever move the cursor forward, so two acks at once can't rewind it.
        $cache = getCache();
        $cursorKey = 'mqtt:cursor:' . $connection->projectId . ':' . $connection->identity['userId'] . ':' . $connection->getClientId();
        $topic = $delivery['topic'];
        $cursor = (int) $delivery['cursor'];

        $current = $cache->loadMany($cursorKey, 3600, [$topic]);
        if ($cursor > (int) ($current[$topic]['sequence'] ?? -1)) {
            $cache->saveMany($cursorKey, [$topic => ['sequence' => $cursor]], 3600);
        }
    }
}
