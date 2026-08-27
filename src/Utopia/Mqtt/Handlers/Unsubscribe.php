<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
use Utopia\Platform\Action;

class Unsubscribe extends Action
{
    public function __construct()
    {
        $this
            ->desc('Remove topic-filter subscriptions')
            ->label(Dispatcher::LABEL_TYPE, Packet::UNSUBSCRIBE)
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
        $offset = 0;
        $packetId = substr($body, 0, 2);
        $offset += 2;

        $subId = Packet::readProperties($body, $offset, $connection->protocol)['user']['subId'] ?? '';
        $offset = Packet::skipProperties($body, $offset, $connection->protocol);

        $count = 0;
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $mqtt->unsubscribeSubscription($connection->fd, $subId ?: $filter);
            $count++;
        }

        // UNSUBACK: packet id (+ property length 0 + one reason code per filter for v5)
        $variable = $packetId;
        if ($connection->protocol >= 5) {
            $variable .= Packet::encodeLength(0) . str_repeat(chr(Packet::REASON_SUCCESS), $count);
        }
        $reply(chr(Packet::UNSUBACK << 4) . Packet::encodeLength(strlen($variable)) . $variable, false);
    }
}
