# Utopia Queue

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/queue`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/queue) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

A task queue: messages, publishers and consumers on Redis, Redis Cluster and NATS JetStream, and a worker server with hooks, retries and concurrency.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-queue = { git = "https://github.com/utopia-rs/queue" }
```

A queue hands work from the code that asks for it to workers that do it later. This library writes each piece of work as a message on a broker (Redis, a Redis Cluster or NATS JetStream), delivers it at least once to a worker, and keeps every failure recoverable: a rejected message waits on a failed list or a dead stream, and a worker that dies mid-job leaves its claim for the next one to reclaim. Brokers and workers are async, so the examples run on a Tokio runtime; the ones that need a server only compile here. This guide covers the common tasks.

## Name a queue

A queue is a name in a namespace. The namespace prefixes every key the broker writes, so applications sharing one Redis keep apart; a job TTL bounds how long a failed message's payload is kept.

```rust
use utopia_queue::Queue;

let mails = Queue::new("mails")?;
assert_eq!(mails.namespace(), "utopia-queue");
assert_eq!(mails.key("queue"), "utopia-queue.queue.mails");

let audits = Queue::with("audits", "{tenant-a}", 3600)?;
assert_eq!(audits.key("failed"), "{tenant-a}.failed.audits");
assert!(Queue::new("").is_err());
```

## Publish messages

`publish` writes one message, `publish_many` several in one round trip. A payload is a PHP array; `payload` builds one from anything serialisable. Each message gets its own pid and the publisher's timestamp.

```rust
use std::sync::Arc;
use utopia_queue::broker::Redis;
use utopia_queue::connection::{Locking, Redis as Connection};
use utopia_queue::{Publisher, Queue, payload};

tokio::runtime::Runtime::new()?.block_on(async {
    let broker = Redis::new(
        Arc::new(Connection::new("localhost", 6379)),
        Arc::new(Locking::new(Connection::new("localhost", 6379))),
    );
    let mails = Queue::new("mails")?;

    broker.publish(&mails, payload(&serde_json::json!({"to": "ada@example.com"}))?).await?;
    broker
        .publish_many(&mails, vec![payload(&serde_json::json!({"to": "grace@example.com"}))?, payload(&serde_json::json!({"to": "alan@example.com"}))?])
        .await?;

    assert_eq!(broker.pending_count(&mails).await?, 3);
    Ok::<_, utopia_queue::Error>(())
})?;
```

## Receive and settle messages

`receive` waits up to its timeout for the first message, then claims up to `n` without waiting for more. Every message must be committed (done) or rejected (failed); a rejected message waits on the failed list for `retry`, and its attempt count goes up when it is requeued.

```rust
use std::sync::Arc;
use utopia_queue::broker::Redis;
use utopia_queue::connection::{Locking, Redis as Connection};
use utopia_queue::{Consumer, Publisher, Queue};

tokio::runtime::Runtime::new()?.block_on(async {
    let broker = Redis::new(
        Arc::new(Connection::new("localhost", 6379)),
        Arc::new(Locking::new(Connection::new("localhost", 6379))),
    );
    let mails = Queue::new("mails")?;

    for message in broker.receive(&mails, 2, 10).await? {
        match message.payload().get_str("to") {
            Some(_) => broker.commit(&mails, &message).await?,
            None => broker.reject(&mails, &message).await?,
        }
    }
    println!("{} failed", broker.failed_count(&mails).await?);
    Ok::<_, utopia_queue::Error>(())
})?;
```

## Choose a codec

Envelopes are JSON by default, written exactly as PHP's `json_encode` writes them. `Igbinary` is smaller and faster; `Compat` reads both, so a queue can change format while old messages are still on it.

```rust
use php_std::{Array, Value};
use utopia_queue::codec::{Codec, Compat, Igbinary, Json};

let mut envelope = Array::new();
envelope.set("pid".into(), Value::from("p1"));
envelope.set("payload".into(), Value::Array(Array::from_list(vec![Value::Int(1)])));
let value = Value::Array(envelope);

assert_eq!(Json.encode(&value)?, br#"{"pid":"p1","payload":[1]}"#);

let reader = Compat::new(Igbinary);
assert_eq!(reader.decode(&Json.encode(&value)?)?, value);
assert_eq!(reader.decode(&reader.encode(&value)?)?, value);
assert_eq!(reader.content_type(), "application/vnd.php.igbinary");
```

## Read an envelope

`Message::from_envelope` reads a decoded envelope the way a broker does, coercing fields like PHP's typed properties. A handler can mark a delivery terminal so the broker never runs it again.

```rust
use php_std::json::{self, Flags};
use php_std::Value;
use utopia_queue::Message;

let decoded = json::decode(br#"{"pid":7,"queue":"mails","timestamp":"1700000000","payload":{"to":"ada"},"attempts":2}"#, Some(true), 512, Flags::NONE)?;
let Value::Array(envelope) = decoded else { unreachable!() };
let message = Message::from_envelope(&envelope)?;

assert_eq!(message.pid().as_bytes(), b"7");
assert_eq!(message.timestamp(), 1_700_000_000);
assert_eq!(message.attempts(), 2);

message.terminal(true);
assert!(message.is_terminal());
```

## Run a worker

A `Server` runs one job per queue between hooks. Params come from the payload (with validators, defaults and aliases), resources are injected by name, and a job that fails is rejected: terminally for a permanent failure, a type error or a value error. `Runtime::Workers` keeps consuming until stopped with up to `coroutines` jobs at once; `Runtime::Drain` empties the queues and returns.

```rust
use std::sync::Arc;
use utopia_queue::adapter::{Adapter, Runtime};
use utopia_queue::broker::Redis;
use utopia_queue::connection::{Locking, Redis as Connection};
use utopia_queue::{Error, Server};
use utopia_validators::Text;

tokio::runtime::Runtime::new()?.block_on(async {
    let broker = Arc::new(Redis::new(
        Arc::new(Connection::new("localhost", 6379)),
        Arc::new(Locking::new(Connection::new("localhost", 6379))),
    ));
    let mut server = Server::new(Adapter::new(broker, 1, Runtime::Drain));

    server
        .job("mails", 1, None)?
        .param("to", php_std::Value::Null, Text::new(256), "Recipient", false)
        .inject("message")
        .action(|scope| async move {
            let to = scope.param("to");
            if to.to_php_string().is_err() {
                return Err(Error::PermanentFailure("no recipient".into()));
            }
            Ok(())
        });
    server.error().inject("error").action(|scope| async move {
        eprintln!("job failed: {:?}", scope.error());
        Ok(())
    });

    Arc::new(server).start().await
})?;
```

## Retry failures and recover stranded work

`retry_with` requeues failed messages with their attempt count, parking those past `max_attempts` (or older than `newer_than` seconds) on the dead list. `maintain` recovers claims whose worker died: it runs from a worker's upkeep loop, at most once per minute across the fleet.

```rust
use std::sync::Arc;
use utopia_queue::broker::Redis;
use utopia_queue::connection::{Locking, Redis as Connection};
use utopia_queue::Queue;

tokio::runtime::Runtime::new()?.block_on(async {
    let broker = Redis::new(
        Arc::new(Connection::new("localhost", 6379)),
        Arc::new(Locking::new(Connection::new("localhost", 6379))),
    );
    let mails = Queue::new("mails")?;

    broker.retry_with(&mails, Some(100), Some(5), Some(86_400)).await?;
    let requeued = broker.reap(&mails, 3_600, Some(100), Some(5), None, None).await?;
    println!("{requeued} stranded claims requeued");
    Ok::<_, utopia_queue::Error>(())
})?;
```

## Publish in the background

`Background` wraps a publisher with a bounded buffer: `enqueue` returns once the message is buffered and reader tasks publish it. A full buffer pushes back, up to a timeout, then fails with `Error::BufferFull`. With a batch interval and size, consecutive messages leave together.

```rust
use std::sync::Arc;
use utopia_queue::broker::{Background, Redis};
use utopia_queue::connection::{Locking, Redis as Connection};
use utopia_queue::{AsyncPublisher, Queue, payload};

tokio::runtime::Runtime::new()?.block_on(async {
    let redis = Arc::new(Redis::new(
        Arc::new(Connection::new("localhost", 6379)),
        Arc::new(Locking::new(Connection::new("localhost", 6379))),
    ));
    let background = Background::new(redis, 512, 1, 0.5, Some((0.01, 100)))?;
    background.start()?;

    let audits = Queue::new("audits")?;
    for n in 0..1_000 {
        background.enqueue(&audits, payload(&serde_json::json!({"n": n}))?).await?;
    }
    background.shutdown().await;
    Ok::<_, utopia_queue::Error>(())
})?;
```

## Use NATS JetStream

The NATS broker keeps each queue as a work-queue stream with a durable pull consumer; redelivery, backoff and dead-lettering are the server's. Settings are validated when the broker is built.

```rust
use utopia_queue::broker::{Nats, NatsConfig};
use utopia_queue::{Consumer, Publisher, Queue, payload};

tokio::runtime::Runtime::new()?.block_on(async {
    let config = NatsConfig { ack_wait: 30.0, max_deliver: 5, backoff: Some(vec![30.0, 60.0, 120.0]), max_ack_pending: Some(64), ..Default::default() };
    let broker = Nats::new("nats://localhost:4222", config)?;
    let audits = Queue::new("audits")?;

    broker.publish(&audits, payload(&serde_json::json!({"event": "login"}))?).await?;
    for message in broker.receive(&audits, 2, 10).await? {
        broker.commit(&audits, &message).await?;
    }
    Ok::<_, utopia_queue::Error>(())
})?;
```

## Pin the clock in tests

Brokers read time and ids from a `Clock`. Replace it to make messages reproducible.

```rust
use std::sync::atomic::{AtomicU64, Ordering};
use utopia_queue::Clock;

struct Fixed(AtomicU64);

impl Clock for Fixed {
    fn time(&self) -> i64 {
        1_700_000_000
    }

    fn uniqid(&self) -> String {
        format!("pid{}", self.0.fetch_add(1, Ordering::SeqCst))
    }

    fn token(&self) -> String {
        "token".into()
    }
}

let clock = Fixed(AtomicU64::new(1));
assert_eq!(clock.uniqid(), "pid1");
assert_eq!(clock.uniqid(), "pid2");
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
