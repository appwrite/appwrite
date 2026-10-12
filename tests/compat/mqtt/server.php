<?php

/**
 * Brokers on the Swoole adapter for the compat e2e and interop cases:
 *
 *   php tests/compat/mqtt/server.php <mode> <host> <tcp port> <websocket port> [<tls port> <cert> <key>]
 *
 * - `fixture`: the E2E fixture broker of packages/mqtt
 *   (tests/Fixtures/Swoole/server.php) on these listeners. Its handlers are
 *   the fixture's: authenticate the CONNECT (the credential "deny" is
 *   refused), track subscriptions per connection, fan PUBLISH out to matching
 *   subscribers, answer PING and re-auth, and reflect what was decoded (a v5
 *   CONNACK echoes the Authentication Method and User Properties; PUBLISH
 *   properties are forwarded unchanged). A PUBLISH to "compat/shutdown" shuts
 *   the adapter down. Its Rust twin is `fixture()` in
 *   crates/tools/compat/src/libs/mqtt.rs.
 * - `broker`: a Utopia\Mqtt\Server with the suite's Recorder handler
 *   (Handlers.php) on these listeners, keep-alives on a TimingWheel draining
 *   every second; a PUBLISH to "compat/shutdown" stops it.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/Handlers.php';

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Packet;
use Utopia\Mqtt\Packet\Specs\V3;
use Utopia\Mqtt\Packet\Specs\V5;
use Utopia\Mqtt\Properties;
use Utopia\Mqtt\Property;
use Utopia\Mqtt\Server;

$mode = $argv[1] ?? 'fixture';
$host = $argv[2] ?? '127.0.0.1';
$transports = [
    new Adapter\Swoole\WebSocket($host, (int) ($argv[4] ?? 18831)),
    new Adapter\Swoole\Tcp($host, (int) ($argv[3] ?? 18830)),
];
if (isset($argv[5], $argv[6], $argv[7])) {
    $transports[] = new Adapter\Swoole\Tls($host, (int) $argv[5], $argv[6], $argv[7]);
}

if ($mode === 'broker') {
    $adapter = new Adapter\Swoole($transports, workers: 1, timer: new Adapter\Swoole\Timers\TimingWheel(1, 1.0));
    $server = null;
    $server = new Server($adapter, new Tests\Compat\Mqtt\Recorder(new \ArrayObject(), function () use (&$server): void {
        $server->stop();
    }));
    $server->start();

    return;
}

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

$adapter = new Adapter\Swoole($transports, workers: 1);

$adapter
    ->onStart(fn (): int => print("mqtt broker started\n"))
    ->onReceive(function (int $fd, string $data) use ($adapter, $matches, &$protocol, &$subscriptions): void {
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
                    $ack = (new Properties())
                        ->add(new Property(Property::AUTHENTICATION_METHOD, (string) $properties->get(Property::AUTHENTICATION_METHOD)))
                        ->add(new Property(Property::USER, $properties->user()));
                    $adapter->send($fd, V5::connack(V5::REASON_SUCCESS, $ack));
                    break;
                }

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
                    $codes .= chr(Packet::QOS_1);
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
                        fn (string $f): bool => $f !== $filter
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
                if ($topic === 'compat/shutdown') {
                    $adapter->shutdown();
                    break;
                }

                foreach ($subscriptions as $subscriberFd => $filters) {
                    foreach ($filters as $filter) {
                        if ($matches($filter, $topic)) {
                            $adapter->send($subscriberFd, ($protocol[$subscriberFd] ?? 4) >= 5
                                ? V5::publish($topic, $payload, 0, 0, $properties)
                                : V3::publish($topic, $payload, 0, 0));
                            break;
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
                $adapter->send($fd, V5::auth(V5::AUTH_SUCCESS));
                break;

            case Packet::DISCONNECT:
                $adapter->close($fd);
                break;
        }
    })
    ->onClose(function (int $fd) use (&$protocol, &$subscriptions): void {
        unset($protocol[$fd], $subscriptions[$fd]);
    })
    ->start();
