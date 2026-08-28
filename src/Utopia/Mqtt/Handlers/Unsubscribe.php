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

        $subId = '';
        if ($connection->protocol >= 5) {
            [$properties, $offset] = Properties::parse($body, $offset);
            $subId = (string) ($properties->user()['subId'] ?? '');
        }

        $count = 0;
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $mqtt->unsubscribeSubscription($connection->fd, $subId ?: $filter);
            $count++;
        }

        // 5.0 carries a Success reason code per filter; 3.1.1 UNSUBACK is bare.
        $reply(
            $connection->protocol >= 5
                ? V5::unsuback($packetId, $count)
                : V3::unsuback($packetId),
            false,
        );
    }
}
