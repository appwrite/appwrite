<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\V3;
use Utopia\Mqtt\Packet\V5;
use Utopia\Mqtt\Properties;
use Utopia\Platform\Action;
use Utopia\Span\Span;

class Publish extends Action
{
    public function __construct()
    {
        $this
            ->desc('Fan a published message out to matching subscribers')
            ->label(Dispatcher::LABEL_TYPE, Packet::PUBLISH)
            ->inject('mqtt')
            ->inject('connection')
            ->inject('packet')
            ->inject('reply')
            ->callback($this->action(...));
    }

    /**
     * @param callable(string, bool): void $reply writes a packet back to this connection (and optionally closes it)
     */
    public function action(Mqtt $mqtt, Connection $connection, Packet $packet, callable $reply): void
    {
        $body = $packet->body;
        $qos = $packet->qos();

        [$topic, $offset] = Packet::readString($body, 0);

        $packetId = null;
        if ($qos > 0) {
            $packetId = substr($body, $offset, 2);
            $offset += 2;
        }
        if ($connection->protocol >= 5) {
            $offset = Properties::skip($body, $offset);
        }
        $payload = substr($body, $offset);

        Span::add('mqtt.topic', $topic);
        Span::add('mqtt.subscribers', count($mqtt->getSubscribers($connection->projectId, $topic)));

        $mqtt->send($connection->projectId, [], [], [$topic], [], ['payload' => $payload, 'qos' => $qos]);

        if ($qos === 1) {
            // PUBACK is a bare packet id in both versions (reason/properties omitted).
            $reply($connection->protocol >= 5 ? V5::puback($packetId) : V3::puback($packetId), false);
        }
    }
}
