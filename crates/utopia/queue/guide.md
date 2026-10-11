A queue hands work from the code that asks for it to workers that do it later. This library writes each piece of work as a message on a broker (Redis, a Redis Cluster or NATS JetStream), delivers it at least once to a worker, and keeps every failure recoverable: a rejected message waits on a failed list or a dead stream, and a worker that dies mid-job leaves its claim for the next one to reclaim. Brokers and workers are async, so the examples run on a Tokio runtime; the ones that need a server only compile here. This guide covers the common tasks.

## Name a queue

A queue is a name in a namespace. The namespace prefixes every key the broker writes, so applications sharing one Redis keep apart; a job TTL bounds how long a failed message's payload is kept.

```
use utopia_queue::Queue;

let mails = Queue::new("mails")?;
assert_eq!(mails.namespace(), "utopia-queue");
assert_eq!(mails.key("queue"), "utopia-queue.queue.mails");

let audits = Queue::with("audits", "{tenant-a}", 3600)?;
assert_eq!(audits.key("failed"), "{tenant-a}.failed.audits");
assert!(Queue::new("").is_err());
# Ok::<(), utopia_queue::Error>(())
```

```php
use Utopia\Queue\Queue;

$mails = new Queue('mails');
echo $mails->namespace, "\n"; // utopia-queue

$audits = new Queue('audits', '{tenant-a}', 3600);
echo $audits->jobTtl, "\n"; // 3600
```

## Publish messages

`publish` writes one message, `publish_many` several in one round trip. A payload is a PHP array; `payload` builds one from anything serialisable. Each message gets its own pid and the publisher's timestamp.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

$broker = new Redis(new Connection('redis'), new Locking(new Connection('redis')));
$mails = new Queue('mails', 'guide-publish');

$broker->publish($mails, ['to' => 'ada@example.com']);
$broker->publishMany($mails, [['to' => 'grace@example.com'], ['to' => 'alan@example.com']]);

echo $broker->getQueueSize($mails), "\n"; // 3
new Connection('redis')->remove('guide-publish.queue.mails');
```

## Receive and settle messages

`receive` waits up to its timeout for the first message, then claims up to `n` without waiting for more. Every message must be committed (done) or rejected (failed); a rejected message waits on the failed list for `retry`, and its attempt count goes up when it is requeued.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

$broker = new Redis(new Connection('redis'), new Locking(new Connection('redis')));
$mails = new Queue('mails', 'guide-receive');
$broker->publishMany($mails, [['to' => 'ada@example.com'], ['oops' => true]]);

foreach ($broker->receive($mails, 2, 10) as $message) {
    isset($message->getPayload()['to']) ? $broker->commit($mails, $message) : $broker->reject($mails, $message);
}
echo $broker->getFailedCount($mails), " failed\n"; // 1 failed
$redis = new \Redis();
$redis->connect('redis');
$redis->del($redis->keys('guide-receive.*'));
```

## Choose a codec

Envelopes are JSON by default, written exactly as PHP's `json_encode` writes them. `Igbinary` is smaller and faster; `Compat` reads both, so a queue can change format while old messages are still on it.

```
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
# Ok::<(), utopia_queue::Error>(())
```

```php
use Utopia\Queue\Codec\Compat;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;

$envelope = ['pid' => 'p1', 'payload' => [1]];
echo new Json()->encode($envelope), "\n"; // {"pid":"p1","payload":[1]}

$reader = new Compat(new Igbinary());
var_dump($reader->decode(new Json()->encode($envelope)) === $envelope); // bool(true)
echo $reader->contentType(), "\n"; // application/vnd.php.igbinary
```

## Read an envelope

`Message::from_envelope` reads a decoded envelope the way a broker does, coercing fields like PHP's typed properties. A handler can mark a delivery terminal so the broker never runs it again.

```
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Message;

$message = new Message(json_decode('{"pid":7,"queue":"mails","timestamp":"1700000000","payload":{"to":"ada"},"attempts":2}', true));
var_dump($message->getPid(), $message->getTimestamp(), $message->getAttempts()); // string(1) "7" int(1700000000) int(2)
var_dump($message->terminal()->isTerminal()); // bool(true)
```

## Run a worker

A `Server` runs one job per queue between hooks. Params come from the payload (with validators, defaults and aliases), resources are injected by name, and a job that fails is rejected: terminally for a permanent failure, a type error or a value error. `Runtime::Workers` keeps consuming until stopped with up to `coroutines` jobs at once; `Runtime::Drain` empties the queues and returns.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Adapter\KubernetesJob;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;
use Utopia\Validator\Text;

$broker = new Redis(new Connection('redis'), new Locking(new Connection('redis')));
$broker->publish(new Queue('mails', 'guide-worker'), ['to' => 'ada@example.com']);

$server = new Server(new KubernetesJob($broker, 1, 'guide-worker'));
$server->job('mails')
    ->param('to', null, new Text(256), 'Recipient')
    ->inject('message')
    ->action(function (string $to, Message $message): void {
        echo "mail to {$to}\n"; // mail to ada@example.com
    });
$server->start();
```

## Retry failures and recover stranded work

`retry_with` requeues failed messages with their attempt count, parking those past `max_attempts` (or older than `newer_than` seconds) on the dead list. `maintain` recovers claims whose worker died: it runs from a worker's upkeep loop, at most once per minute across the fleet.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

$broker = new Redis(new Connection('redis'), new Locking(new Connection('redis')));
$mails = new Queue('mails', 'guide-retry');

$broker->retry($mails, 100, maxAttempts: 5, newerThan: 86400);
echo $broker->reap($mails, 3600, 100, maxAttempts: 5), " stranded claims requeued\n"; // 0 stranded claims requeued
```

## Publish in the background

`Background` wraps a publisher with a bounded buffer: `enqueue` returns once the message is buffered and reader tasks publish it. A full buffer pushes back, up to a timeout, then fails with `Error::BufferFull`. With a batch interval and size, consecutive messages leave together.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Queue\Broker\Background;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Connection\Locking;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

use function Swoole\Coroutine\run;

$background = new Background(new Redis(new Connection('redis'), new Locking(new Connection('redis'))), 512, 1, 0.5, maxBatchInterval: 0.01, maxBatchSize: 100);
$audits = new Queue('audits', 'guide-background');

run(function () use ($background, $audits): void {
    $background->start();
    foreach (range(1, 10) as $n) {
        $background->enqueue($audits, ['n' => $n]);
    }
    $background->shutdown();
    echo $background->getQueueSize($audits), "\n"; // 10
    new Connection('redis')->remove('guide-background.queue.audits');
});
```

## Use NATS JetStream

The NATS broker keeps each queue as a work-queue stream with a durable pull consumer; redelivery, backoff and dead-lettering are the server's. Settings are validated when the broker is built.

```no_run
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\Queue\Broker\Nats;

$broker = new Nats(fn (): Connection => Connection::connect('nats://localhost:4222'), ackWait: 30.0, maxDeliver: 5, backoff: [30.0, 60.0, 120.0], maxAckPending: 64);
var_dump($broker->inFlightCeiling()); // int(64)
```

## Pin the clock in tests

Brokers read time and ids from a `Clock`. Replace it to make messages reproducible.

```
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

```php
// PHP brokers call time(), uniqid() and random_bytes() directly; tests
// shadow those functions in the broker's namespace instead.
$pid = uniqid('', true);
echo strlen($pid), "\n"; // 23
```
