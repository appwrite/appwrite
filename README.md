# Utopia MQTT

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia MQTT is a PHP toolkit for building MQTT brokers (3.1.1 and 5.0). You implement one interface of typed control packets and the library owns the wire — framing, decoding, per-version encoding, packet ids, the QoS handshake, keep-alive reaping, and subscription matching. This library is maintained by the [Appwrite team](https://appwrite.io) and is framework-agnostic.

## Installation

```bash
composer require utopia-php/mqtt
```

The library requires PHP 8.1+. The `Swoole` adapter additionally needs the Swoole extension.

## Quick start

Create a broker by wiring an adapter (which transports to listen on) with a handler (how packets are answered). The handler below accepts every client, grants every subscription, and fans each publish out to its subscribers.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Utopia\Mqtt\Adapter;
use Utopia\Mqtt\Connection;
use Utopia\Mqtt\Handler;
use Utopia\Mqtt\Server;
use Utopia\Mqtt\Packet\Auth;
use Utopia\Mqtt\Packet\Connack;
use Utopia\Mqtt\Packet\Connect;
use Utopia\Mqtt\Packet\Disconnect;
use Utopia\Mqtt\Packet\Publish;
use Utopia\Mqtt\Packet\Puback;
use Utopia\Mqtt\Packet\Suback;
use Utopia\Mqtt\Packet\Subscribe;
use Utopia\Mqtt\Packet\Unsubscribe;
use Utopia\Mqtt\Packet\Unsuback;

final class Broker implements Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        return Connack::accept();
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $suback = new Suback();
        foreach ($subscribe->filters() as $filter) {
            $suback->grant($filter->qos);
        }

        return $suback;
    }

    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
        foreach ($subscribers as $subscriber) {
            $subscriber->publish($publish->topic, $publish->payload, qos: $publish->qos);
        }
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect
    {
        return Auth::success($auth->method);
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        return new Unsuback();
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
    }
}

$adapter = new Adapter\Swoole([new Adapter\Swoole\Tcp('0.0.0.0', 1883)]);

$server = new Server($adapter, new Broker());
$server->start();
```

The broker listens on TCP port `1883` and speaks both 3.1.1 and 5.0. Implement `Handler` to add authentication, per-topic authorization, offline delivery, or any policy your broker needs.

## Handlers

The `Handler` is the whole application surface. Each method receives a decoded control packet and returns a decision — itself a packet, which the broker encodes for the client's negotiated version. You never touch bytes, packet ids, or version encoders.

```php
interface Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack|Auth;
    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect;
    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback;
    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback;
    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void;
    public function onPuback(Puback $puback, Connection $connection): void;
    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void;
}
```

Authenticate a CONNECT by returning a `Connack` (v5 enhanced auth may return an `Auth` challenge instead):

```php
public function onConnect(Connect $connect, Connection $connection): Connack|Auth
{
    $identity = $this->authenticate($connect->username, $connect->password);
    if ($identity === null) {
        return Connack::refuse(Connack::NOT_AUTHORIZED); // encoded as the right CONNACK for v3 or v5
    }

    $connection->identity = $identity;      // opaque application state, stored and never read
    $connection->prefix   = $identity['tenant']; // the isolation key subscriptions are matched under

    return Connack::accept();
}
```

Authorize each subscribed filter, granting a QoS or denying it:

```php
public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
{
    $suback = new Suback();
    foreach ($subscribe->filters() as $filter) {
        $suback->grant($this->acl->allows($connection->identity, $filter->topic) ? $filter->qos : Suback::DENIED);
    }

    return $suback;
}
```

Delivery is yours. `onPublish` receives the local subscribers the broker matched; publish to each `Connection`, and acknowledge the sender's QoS 1 message once you have accepted it:

```php
public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
{
    if (! $this->acl->canPublish($connection->identity, $publish->topic)) {
        return; // dropped
    }

    foreach ($subscribers as $subscriber) {
        $subscriber->publish($publish->topic, $publish->payload, qos: $publish->qos);
    }

    if ($publish->qos === 1) {
        $connection->puback($publish->packetId);
    }
}
```

Because the handler is a single narrow interface, cross-cutting policy composes as decorators that wrap another handler:

```php
final readonly class RateLimited implements Handler
{
    public function __construct(private Handler $inner) {}

    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        return $this->isFlooding($connection) ? Connack::refuse(Connack::SERVER_BUSY) : $this->inner->onConnect($connect, $connection);
    }

    // delegate the rest to $this->inner …
}

$server = new Server($adapter, new RateLimited(new Broker()));
```

The packet each method works with:

| Received | Returned |
|---|---|
| `Connect` — `clientId`, `cleanStart`, `keepAlive`, `username`, `password`, `will`, v5 `authMethod`/`authData`, `userProperties()` | `Connack::accept()` / `Connack::refuse($reason)` |
| `Subscribe` — `filters()` of `Filter { topic, qos }` | `Suback` — `grant($qos)` / `deny()` per filter |
| `Unsubscribe` — `filters()` | `Unsuback` — `success()` / `fail()` per filter |
| `Publish` — `topic`, `payload`, `qos`, `dup`, `retain`, `packetId`, `userProperties()` | delivered, not replied |
| `Auth` — `method`, `data`, `userProperties()` | `Auth::success()` / `Auth::challenge($method, $data)` |
| `Puback` — `packetId` | resolve with `$connection->acknowledge($packetId)` |

## Connections

The `Connection` handed to every method is the per-client handle. It carries transport state (`protocol`, `cleanStart`, `keepAlive`), the isolation key subscriptions match under (`prefix`), and an opaque `identity` the library never reads. You publish through it:

```php
$connection->publish($topic, $payload, qos: 1, dup: false, sequence: 42);
$connection->puback($packetId);   // acknowledge an inbound QoS 1 PUBLISH
$connection->disconnect($reason);  // send a v5 DISCONNECT and close
```

For QoS 1 it tracks in-flight deliveries, so a PUBACK resolves back to the topic and durable sequence it acknowledges (`track` / `resume` / `acknowledge`), advancing a cursor only across a contiguous run of acks — the foundation for offline replay on reconnect.

## Adapters and transports

An adapter composes one or more transports into a single process. The `Swoole` adapter ships `Tcp`, `Tls`, and `WebSocket` transports; UDP-style browsers over WebSocket and native clients over TCP feed the same handler.

```php
use Utopia\Mqtt\Adapter;

$adapter = new Adapter\Swoole([
    new Adapter\Swoole\Tcp('0.0.0.0', 1883),
    new Adapter\Swoole\Tls('0.0.0.0', 8883, cert: '/etc/ssl/mqtt.crt', key: '/etc/ssl/mqtt.key'),
    new Adapter\Swoole\WebSocket('0.0.0.0', 8083),
], workers: 4);
```

A WebSocket message may carry several or partial MQTT packets, so the adapter reassembles whole packets before dispatch and pushes binary frames on send. Keep-alive reaping is intrinsic to MQTT and runs inside the adapter: every inbound packet re-arms a connection's deadline, and a client silent past `keepAlive × 1.5` is closed, surfacing as `onDisconnect(null, ...)`.

## Telemetry

Pass a [utopia-php/telemetry](https://github.com/utopia-php/telemetry) adapter to record broker metrics — packets received by type, connections opened and active, and subscriptions granted or denied. It defaults to a no-op adapter.

```php
$server->setTelemetry($telemetry);
```

Application-specific metrics (authentication latency, delivery counts, and so on) stay in your handler.

## MQTT client

The bundled `Client` talks to a broker over TCP/TLS (`mqtt://` / `mqtts://`): `connect`, `send`, `receive` / `listen`, with `onOpen` / `onReceive` / `onClose` / `onError`.

## Tests

```bash
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
