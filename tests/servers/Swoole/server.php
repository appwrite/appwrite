<?php

require_once __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;

/**
 * A minimal but real MQTT broker built on the package, used by the e2e suite. It
 * speaks both 3.1.1 and 5.0: authenticates the CONNECT (credential "deny" is
 * refused), tracks subscriptions per connection, fans PUBLISH out to matching
 * subscribers (with + / # wildcards), and answers PING and re-auth. It keeps state
 * in the single worker (setWorkerNumber(1)).
 *
 * So the e2e can assert the server DECODED correctly (not merely that it replied),
 * it reflects what it parsed: a v5 CONNACK echoes back the Authentication Method and
 * User Property metadata read off the CONNECT, and PUBLISH properties are forwarded
 * to subscribers unchanged.
 */

/** @var array<int, int> fd => protocol level (4 or 5) */
$protocol = [];
/** @var array<int, array<int, string>> fd => topic filters */
$subscriptions = [];

$matches = function (string $filter, string $topic): bool {
    $f = explode('/', $filter);
    $t = explode('/', $topic);
    foreach ($f as $i => $segment) {
        if ($segment === '#') {
            return true;
        }
        if (!isset($t[$i])) {
            return false;
        }
        if ($segment !== '+' && $segment !== $t[$i]) {
            return false;
        }
    }
    return count($f) === count($t);
};

$adapter = new Adapter\Swoole([new Adapter\Swoole\Tcp('0.0.0.0', 1883)], workers: 1);

$adapter
    ->onStart(fn () => print("mqtt broker started\n"))
    ->onReceive(function (int $fd, string $data) use ($adapter, $matches, &$protocol, &$subscriptions) {
        $packet = Packet::parse($data);

        switch ($packet->type) {
            case Packet::CONNECT:
                $body = $packet->body;
                [, $offset] = Packet::readString($body, 0); // "MQTT"
                $level = ord($body[$offset]);
                $offset++;
                $flags = ord($body[$offset]);
                $offset++;
                $offset += 2; // keep alive
                $protocol[$fd] = $level;

                if ($level >= 5) {
                    [$properties] = Properties::parse($body, $offset);
                    $credential = $properties->get(Property::AUTHENTICATION_DATA);
                    if ($credential === 'deny') {
                        $adapter->send($fd, V5::connack(V5::REASON_NOT_AUTHORIZED));
                        $adapter->close($fd);
                        break;
                    }
                    // Reflect the decoded auth method + metadata so the e2e can verify them.
                    $ack = (new Properties())
                        ->add(new Property(Property::AUTHENTICATION_METHOD, (string) $properties->get(Property::AUTHENTICATION_METHOD)))
                        ->add(new Property(Property::USER, $properties->user()));
                    $adapter->send($fd, V5::connack(V5::REASON_SUCCESS, $ack));
                    break;
                }

                // 3.1.1: read client id, then username/password per the connect flags.
                [, $offset] = Packet::readString($body, $offset); // client id
                $password = '';
                if ($flags & 0x80) {
                    [, $offset] = Packet::readString($body, $offset); // username
                }
                if ($flags & 0x40) {
                    [$password, $offset] = Packet::readString($body, $offset);
                }
                if ($password === 'deny') {
                    $adapter->send($fd, V3::connack(V3::RETURN_NOT_AUTHORIZED));
                    $adapter->close($fd);
                    break;
                }
                $adapter->send($fd, V3::connack(V3::RETURN_ACCEPTED));
                break;

            case Packet::SUBSCRIBE:
                $body = $packet->body;
                $packetId = substr($body, 0, 2);
                $offset = 2;
                if (($protocol[$fd] ?? 4) >= 5) {
                    $offset = Properties::skip($body, $offset);
                }
                $codes = '';
                while ($offset < strlen($body)) {
                    [$filter, $offset] = Packet::readString($body, $offset);
                    $offset++; // options byte
                    $subscriptions[$fd][] = $filter;
                    $codes .= chr(Packet::QOS_1); // grant QoS 1
                }
                $adapter->send($fd, ($protocol[$fd] >= 5)
                    ? V5::suback($packetId, $codes)
                    : V3::suback($packetId, $codes));
                break;

            case Packet::UNSUBSCRIBE:
                $body = $packet->body;
                $packetId = substr($body, 0, 2);
                $offset = 2;
                if (($protocol[$fd] ?? 4) >= 5) {
                    $offset = Properties::skip($body, $offset);
                }
                $count = 0;
                while ($offset < strlen($body)) {
                    [$filter, $offset] = Packet::readString($body, $offset);
                    $subscriptions[$fd] = array_values(array_filter(
                        $subscriptions[$fd] ?? [],
                        fn (string $f) => $f !== $filter
                    ));
                    $count++;
                }
                $adapter->send($fd, ($protocol[$fd] >= 5)
                    ? V5::unsuback($packetId, $count)
                    : V3::unsuback($packetId));
                break;

            case Packet::PUBLISH:
                $qos = $packet->qos();
                [$topic, $offset] = Packet::readString($packet->body, 0);
                $packetId = '';
                if ($qos > 0) {
                    $packetId = substr($packet->body, $offset, 2);
                    $offset += 2;
                }
                $properties = new Properties();
                if (($protocol[$fd] ?? 4) >= 5) {
                    [$properties, $offset] = Properties::parse($packet->body, $offset);
                }
                $payload = substr($packet->body, $offset);

                foreach ($subscriptions as $subscriberFd => $filters) {
                    foreach ($filters as $filter) {
                        if ($matches($filter, $topic)) {
                            $adapter->send($subscriberFd, ($protocol[$subscriberFd] ?? 4) >= 5
                                ? V5::publish($topic, $payload, 0, 0, $properties) // forward properties unchanged
                                : V3::publish($topic, $payload, 0, 0));
                            break; // one delivery per subscriber
                        }
                    }
                }

                if ($qos === 1) {
                    $adapter->send($fd, ($protocol[$fd] >= 5)
                        ? V5::puback($packetId)
                        : V3::puback($packetId));
                }
                break;

            case Packet::PINGREQ:
                $adapter->send($fd, Packet::pingresp());
                break;

            case Packet::AUTH:
                // Re-authentication: acknowledge with an AUTH success.
                $adapter->send($fd, V5::auth(V5::AUTH_SUCCESS));
                break;

            case Packet::DISCONNECT:
                $adapter->close($fd);
                break;
        }
    })
    ->onClose(function (int $fd) use (&$protocol, &$subscriptions) {
        unset($protocol[$fd], $subscriptions[$fd]);
    })
    ->start();
