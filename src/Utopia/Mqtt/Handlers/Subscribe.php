<?php

namespace Utopia\Mqtt\Handlers;

use Appwrite\Messaging\Adapter\Mqtt;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Dispatcher;
use Utopia\Mqtt\Packet;
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

        $subId = Packet::readProperties($body, $offset, $connection->protocol)['user']['subId'] ?? '';
        $offset = Packet::skipProperties($body, $offset, $connection->protocol);

        $identity = $connection->identity;

        $granted = '';
        while ($offset < strlen($body)) {
            [$filter, $offset] = Packet::readString($body, $offset);
            $offset += 1; // subscription options byte
            Span::add('mqtt.topic', $filter);

            // ACL: an unauthorized filter is answered Not Authorized and never stored.
            if ($authorizer !== null && !$authorizer($identity, $filter)) {
                $granted .= chr(Packet::REASON_NOT_AUTHORIZED);
                $mqtt->metrics->subscriptions->add(1, ['result' => 'denied']);
                Span::add('mqtt.result', 'denied');
                continue;
            }

            $mqtt->subscribe($connection->projectId, $connection->fd, $subId ?: $filter, [], [$filter]);
            $granted .= chr(Packet::QOS_1); // granted max QoS 1
            $mqtt->metrics->subscriptions->add(1, ['result' => 'granted']);
        }

        // SUBACK: packet id (+ property length 0 for v5) + granted codes
        $variable = $packetId . ($connection->protocol >= 5 ? Packet::encodeLength(0) : '') . $granted;
        $reply(chr(Packet::SUBACK << 4) . Packet::encodeLength(strlen($variable)) . $variable, false);
    }
}
