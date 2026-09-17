# Utopia MQTT

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia MQTT is a deep abstraction for building MQTT brokers (3.1.1 and 5.0). You implement one interface — a `Handler` of typed control packets — and the library owns the wire: framing, decoding, per-version encoding, packet ids, the QoS handshake, keep-alive reaping, and the subscription index. Your code never sees a byte. This library is maintained by the [Appwrite team](https://appwrite.io).

The pieces:

- **`Server`** — the broker engine. It decodes each inbound packet, calls your `Handler`, encodes the reply for the negotiated version, and reaps idle connections.
- **`Handler`** — the one seam you implement: a method per inbound control packet, receiving a decoded packet and returning a decision (also a decoded packet). Authentication, authorization, delivery and persistence live here.
- **`Adapter` + transports** — the runtime and the wires it listens on (`Swoole` today, with `Tcp`, `Tls` and `WebSocket` transports composed onto it).
- **`Connection`** — the per-client handle you publish through.

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it is dependency free and can be used standalone with any other PHP project or framework.

## Getting started

Install using composer:

```bash
composer require utopia-php/mqtt
```

A broker is a `Handler` plus the transports to serve it on:

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

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

final class InMemoryBroker implements Handler
{
    public function onConnect(Connect $connect, Connection $connection): Connack|Auth
    {
        // Set the isolation key subscriptions are matched under, and stash any identity.
        $connection->prefix = 'default';
        $connection->identity = ['clientId' => $connect->clientId];

        return Connack::accept();
    }

    public function onAuthenticate(Auth $auth, Connection $connection): Connack|Auth|Disconnect
    {
        return Auth::success($auth->method);
    }

    public function onSubscribe(Subscribe $subscribe, Connection $connection): Suback
    {
        $suback = new Suback();
        foreach ($subscribe->filters() as $filter) {
            $suback->grant($filter->qos); // or $suback->deny()
        }

        return $suback;
    }

    public function onUnsubscribe(Unsubscribe $unsubscribe, Connection $connection): Unsuback
    {
        $unsuback = new Unsuback();
        foreach ($unsubscribe->filters() as $filter) {
            $unsuback->success();
        }

        return $unsuback;
    }

    public function onPublish(Publish $publish, Connection $connection, iterable $subscribers): void
    {
        // The broker matched the local subscribers; deliver to each.
        foreach ($subscribers as $subscriber) {
            $subscriber->publish($publish->topic, $publish->payload, qos: $publish->qos);
        }

        if ($publish->qos === 1) {
            $connection->puback($publish->packetId); // ack once you have accepted the message
        }
    }

    public function onPuback(Puback $puback, Connection $connection): void
    {
        $connection->acknowledge($puback->packetId); // advance the QoS 1 delivery cursor
    }

    public function onDisconnect(?Disconnect $disconnect, Connection $connection): void
    {
        // The connection is gone; $disconnect is null on a socket drop or keep-alive reap.
    }
}

$adapter = new Adapter\Swoole([
    new Adapter\Swoole\Tcp(port: 1883),
    new Adapter\Swoole\WebSocket(port: 8083),
]);

$server = new Server($adapter, new InMemoryBroker());
$server->onStart(fn () => print("MQTT broker up\n"));
$server->error(fn (\Throwable $error, string $phase) => error_log("[{$phase}] {$error->getMessage()}"));
$server->start();
```

## The Handler

The whole application surface is one interface. Its method set is the complete list of inbound control packets that carry a decision — the rest (PINGREQ, the QoS handshake) is the broker's job and never surfaces:

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

- **Return types are decoded packets, not bytes.** Return `Connack::refuse(Connack::NOT_AUTHORIZED)` and the broker encodes the right CONNACK for a 3.1.1 or 5.0 client. `onConnect`/`onAuthenticate` may also return an `Auth` challenge (v5 enhanced authentication).
- **Delivery is yours.** `onPublish` receives the local subscribers the broker matched; you deliver by calling `publish()` on each. The QoS 1 PUBACK is explicit (`$connection->puback(...)`) so you can ack only after durably accepting a message.
- **Everything speaks domain terms.** Because it is a single narrow interface, cross-cutting policy (auth, rate limiting, telemetry) composes as decorators that wrap another `Handler`.

## Packets

Each control packet is a typed value object under `Utopia\Mqtt\Packet`. Inbound packets are decoded and handed to the handler; response packets are what the handler returns.

| Received | Returned |
|---|---|
| `Connect` (`clientId`, `cleanStart`, `keepAlive`, `username`, `password`, `will`, v5 `authMethod`/`authData`, `userProperties()`) | `Connack::accept()` / `Connack::refuse($reason)` |
| `Subscribe` (`filters()` → `Filter { topic, qos }`) | `Suback` (`grant($qos)` / `deny()` per filter) |
| `Unsubscribe` (`filters()`) | `Unsuback` (`success()` / `fail()` per filter) |
| `Publish` (`topic`, `payload`, `qos`, `dup`, `retain`, `packetId`, `userProperties()`) | — (delivered, not replied) |
| `Puback` (`packetId`, `reasonCode`) | — |
| `Auth` (`method`, `data`, `userProperties()`) | `Auth::success()` / `Auth::challenge($method, $data)` |
| `Disconnect` (`reasonCode`) | — |

## Connection

The per-client handle, keyed by its file descriptor. It carries transport state (`protocol`, `cleanStart`, `keepAlive`), the isolation key subscriptions are matched under (`prefix`), and an opaque `identity` the library never reads. You publish through it:

```php
$connection->publish($topic, $payload, qos: 1, dup: false, sequence: 42); // frame + send for its version
$connection->puback($packetId);        // acknowledge an inbound QoS 1 PUBLISH
$connection->disconnect($reason);       // send a v5 DISCONNECT and close
```

For QoS 1 it also tracks in-flight deliveries so a PUBACK resolves back to the topic and durable sequence it acknowledges, advancing a cursor only across a contiguous run of acks (`track` / `resume` / `acknowledge`). Offline replay is built on this.

## Adapters and transports

An `Adapter` is the runtime and composes one or more transports. The `Swoole` adapter ships `Tcp`, `Tls`, and `WebSocket` transports; a `WebSocket` transport becomes the master listener and raw MQTT is added alongside it, so browsers and native clients feed the same `Handler`.

```php
use Utopia\Mqtt\Adapter;

$adapter = new Adapter\Swoole([
    new Adapter\Swoole\WebSocket('0.0.0.0', 8083),
    new Adapter\Swoole\Tcp('0.0.0.0', 1883),
    new Adapter\Swoole\Tls('0.0.0.0', 8883, cert: '/etc/ssl/mqtt.crt', key: '/etc/ssl/mqtt.key'),
], workers: 4);
```

A WebSocket message may carry several or partial MQTT packets, so the adapter reassembles whole packets before dispatch and pushes binary frames on send — the `Handler` is identical across carriers.

## Keep-alive

Keep-alive reaping is intrinsic to MQTT, so the adapter runs it: a `Timer` (the `TimingWheel`, a hashed timing wheel) buckets each connection by its deadline and closes any client gone silent past `keepAlive × 1.5`. Every inbound packet re-arms the deadline. A reaped connection surfaces as `onDisconnect(null, ...)`. None of this is wired by your code.

## Client

The bundled `Client` is a small broker client over TCP/TLS (`mqtt://` / `mqtts://`) for talking to a broker: `connect`, `send`, `receive` / `listen`, with `onOpen` / `onReceive` / `onClose` / `onError`.

## System requirements

Utopia MQTT requires PHP 8.1 or later. We recommend using the latest PHP version whenever possible. The `Adapter\Swoole` implementation additionally requires the Swoole extension.

## Tests

To run all unit tests, use the following Composer command:

```bash
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
