# Utopia MQTT

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/mqtt`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/mqtt) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Building blocks for MQTT 5.0 and 3.1.1 brokers: the packet codec, a subscription trie, per-connection delivery state, a broker over adapters (Network on TCP, TLS and WebSocket; in-memory) with keep-alive timers, and a client.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-mqtt = { git = "https://github.com/utopia-rs/mqtt" }
```

An MQTT broker keeps many clients connected, authenticates them, remembers what each subscribed to, and delivers every message published on a topic to the clients whose filters match it. This library gives you the pieces to build one that speaks MQTT 5.0 and 3.1.1: a packet codec, a subscription store, per-connection delivery state, and a `Server` that runs your decisions (a `Handler`) on an adapter: `Network` on TCP, TLS and WebSocket listeners, or `Memory` in process for tests. A `Client` connects to any broker. This guide covers the common tasks.

## Decode what a client sends

On a TCP connection each read is one packet; on WebSocket a message can hold several packets, or part of one, so split the stream with `frames` and keep the remainder. `Packet::parse` reads the fixed header; the packet types decode the rest. A `Publish` decodes differently for each protocol version, so pass the level the client's CONNECT gave.

```rust
use utopia_mqtt::packet::{frames, Connect, Kind, Packet, Publish};
use utopia_mqtt::spec::{v3, v5};

let mut stream = v3::connect(b"sensor-7", 30, true, b"", b"");
stream.extend(v3::publish(b"sensors/7/temp", b"21.5", 0, 0, false));
stream.extend(&v5::puback(b"\x00\x01")[..2]); // the start of the next packet

let (packets, rest) = frames(&stream);
assert_eq!((packets.len(), rest.len()), (2, 2));

let connect = Connect::decode(Packet::parse(packets[0])?.body)?;
assert_eq!((connect.client_id.as_slice(), connect.keep_alive), (&b"sensor-7"[..], 30));

let packet = Packet::parse(packets[1])?;
assert_eq!(packet.kind, Kind::Publish);
let publish = Publish::decode(&packet, connect.protocol_level)?;
assert_eq!(publish.payload, b"21.5");
```

## Answer in the client's protocol version

`spec::v3` and `spec::v5` encode every packet. 5.0 acknowledgements carry a reason code and a property block; 3.1.1 ones have neither. Properties are typed by their identifier, and a User Property carries one key and value.

```rust
use utopia_mqtt::spec::{v3, v5};
use utopia_mqtt::{Properties, Property};

let welcome = Properties::new()
    .with(Property::bytes(Property::REASON_STRING, "welcome"))
    .with(Property::user("region", "eu"));
let connack = v5::connack(v5::REASON_SUCCESS, Some(&welcome))?;
assert_eq!(&connack[..4], b"\x20\x1a\x00\x00");

assert_eq!(v3::connack(v3::RETURN_NOT_AUTHORIZED), b"\x20\x02\x00\x05");
assert_eq!(v5::suback(b"\x00\x01", &[1, 0x80], None)?, b"\x90\x05\x00\x01\x00\x01\x80");
```

## Route publishes with the store

The store indexes subscriptions by a prefix (whatever isolates one set of clients from another: a tenant, a project) and by topic level. `+` matches one level, `#` the rest, and a wildcard never matches a `$`-prefixed system topic. A connection matched by several filters gets the highest QoS granted.

```rust
use utopia_mqtt::Store;

let mut store = Store::new();
store.subscribe(b"project-1", b"user-a", b"orders/+/created", 1, 1);
store.subscribe(b"project-1", b"user-b", b"orders/#", 2, 0);
store.subscribe(b"project-2", b"user-c", b"orders/#", 3, 1);
store.subscribe(b"project-1", b"user-b", b"orders/eu/created", 2, 1);

let found: Vec<_> = store.subscribers(b"project-1", b"orders/eu/created").into_iter().collect();
assert_eq!(found, [(2, 1), (1, 1)]);
assert!(store.subscribers(b"project-1", b"$SYS/uptime").is_empty());

store.close(2);
assert_eq!(store.subscribers(b"project-1", b"orders/eu/created").len(), 1);
```

## Track QoS 1 deliveries

A connection draws packet ids for QoS 1 deliveries and, given each message's sequence, matches every PUBACK back to it. The topic's cursor moves only across acknowledged sequences without a gap, so it is always safe to persist and replay from.

```rust
use utopia_mqtt::Connection;

let connection = Connection::new(1, None);
connection.resume(b"orders", 41); // replay starts after 41
connection.track(1, b"orders", 42);
connection.track(2, b"orders", 43);

assert_eq!(connection.acknowledge(2).unwrap().cursor, 41); // 42 is still out
assert_eq!(connection.acknowledge(1).unwrap().cursor, 43);
assert!(connection.acknowledge(1).is_none());
```

## Run a broker

Implement `Handler` with your decisions and give it to a `Server` on the `Network` adapter. The server decodes every packet for the client's protocol version, keeps the store, answers PINGREQ and closes clients that stay silent past their keep-alive. Here a broker accepts everyone, grants every filter at QoS 0 and fans publishes out; a `Client` subscribes and receives its own message.

```rust
use utopia_mqtt::client::{Client, Options};
use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Packet, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
use utopia_mqtt::spec::v3;
use utopia_mqtt::{Connection, Error, Handler, Server, Network, Transport};

struct Open;

impl Handler for Open {
    fn on_connect(&self, _: &Connect, _: &Connection) -> Result<ConnectReply, Error> {
        Ok(ConnectReply::Connack(Connack::accept(false, None)))
    }
    fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
        Ok(AuthReply::Auth(Auth::success(&auth.method)))
    }
    fn on_subscribe(&self, subscribe: &Subscribe, _: &Connection) -> Result<Suback, Error> {
        let mut suback = Suback::new();
        for _ in &subscribe.filters {
            suback.grant(0);
        }
        Ok(suback)
    }
    fn on_unsubscribe(&self, _: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
        Ok(Unsuback::new())
    }
    fn on_publish(&self, publish: &Publish, _: &Connection, subscribers: &[Subscriber]) -> Result<(), Error> {
        for (subscriber, _) in subscribers {
            subscriber.publish(&publish.topic, &publish.payload, 0, false, None)?;
        }
        Ok(())
    }
    fn on_puback(&self, _: &Puback, _: &Connection) -> Result<(), Error> {
        Ok(())
    }
    fn on_disconnect(&self, _: Option<&Disconnect>, _: &Connection) -> Result<(), Error> {
        Ok(())
    }
}

utopia_async::Runtime::new()?.block_on(async {
    let adapter = Network::new(vec![Transport::tcp("127.0.0.1", 0)], 1, None)?;
    let server = Server::new(adapter.clone(), Open);
    let running = server.clone();
    let task = utopia_async::spawn(async move { running.start().await });
    let addr = adapter.listening().await[0];

    let mut client = Client::new(&format!("mqtt://{addr}"), Options { timeout: 5.0 })?;
    client.connect().await?;
    client.send(&v3::connect(b"me", 60, true, b"", b"")).await?;
    client.receive().await?; // CONNACK
    client.send(&v3::subscribe(1, &[b"chat/#"], 0)).await?;
    client.receive().await?; // SUBACK
    client.send(&v3::publish(b"chat/general", b"hi", 0, 0, false)).await?;

    let delivery = client.receive().await?.unwrap();
    assert_eq!(Packet::parse(&delivery)?.body, b"\x00\x0cchat/generalhi");

    client.close().await;
    server.stop();
    task.await.ok();
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Test a broker in process

The `Memory` adapter has no network: open connections, deliver packets and read back what the broker sent and closed. Hooks run on your thread, so a test is a straight line.

```rust
use utopia_mqtt::packet::{Auth, Connack, Connect, Disconnect, Puback, Publish, Suback, Subscribe, Unsuback, Unsubscribe};
use utopia_mqtt::server::{AuthReply, ConnectReply, Subscriber};
use utopia_mqtt::spec::v5;
use utopia_mqtt::{Connection, Error, Handler, Memory, Server};

struct Strict;

impl Handler for Strict {
    fn on_connect(&self, connect: &Connect, _: &Connection) -> Result<ConnectReply, Error> {
        let known = connect.auth_data.as_deref() == Some(b"secret");
        Ok(ConnectReply::Connack(if known { Connack::accept(false, None) } else { Connack::refuse(Connack::NOT_AUTHORIZED, None) }))
    }
    fn on_authenticate(&self, auth: &Auth, _: &Connection) -> Result<AuthReply, Error> {
        Ok(AuthReply::Auth(Auth::success(&auth.method)))
    }
    fn on_subscribe(&self, _: &Subscribe, _: &Connection) -> Result<Suback, Error> {
        Ok(Suback::new())
    }
    fn on_unsubscribe(&self, _: &Unsubscribe, _: &Connection) -> Result<Unsuback, Error> {
        Ok(Unsuback::new())
    }
    fn on_publish(&self, _: &Publish, _: &Connection, _: &[Subscriber]) -> Result<(), Error> {
        Ok(())
    }
    fn on_puback(&self, _: &Puback, _: &Connection) -> Result<(), Error> {
        Ok(())
    }
    fn on_disconnect(&self, _: Option<&Disconnect>, _: &Connection) -> Result<(), Error> {
        Ok(())
    }
}

let adapter = Memory::new();
let server = Server::new(adapter.clone(), Strict);
utopia_async::Builder::current_thread().build()?.block_on(server.start());

adapter.open(1)?;
adapter.receive(1, &v5::connect(b"anonymous", 60, true, None)?)?;

assert_eq!(adapter.sent()[0].data, v5::connack(Connack::NOT_AUTHORIZED, None)?);
assert_eq!(adapter.closed(), [1]);
```

## Reap silent clients

A client promises to send something at least every keep-alive interval. The server schedules each connection on the adapter's timer at CONNECT and again on every packet; the `TimingWheel` drains one-second buckets on a heartbeat and closes the connections whose time ran out (1.5 keep-alive intervals by default). `NoTimer` never reaps.

```rust
use std::sync::{Arc, Mutex};

use utopia_mqtt::{Timer, TimingWheel};

let now = php_std::datetime::time();
let wheel = TimingWheel::new(20, 1.5, Some(now));
let reaped = Arc::new(Mutex::new(Vec::new()));
let seen = reaped.clone();
wheel.on_tick(Arc::new(move |connection| seen.lock().unwrap().push(connection)));

wheel.schedule(1, 60); // silent for 90 seconds
wheel.schedule(2, 10); // silent for 15 seconds
wheel.schedule(2, 60); // it sent a packet: 90 seconds again
wheel.drain(now + 30);
assert!(reaped.lock().unwrap().is_empty());
wheel.drain(now + 100);
assert_eq!(*reaped.lock().unwrap(), [1, 2]);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
