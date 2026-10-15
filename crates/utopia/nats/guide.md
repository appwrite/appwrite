NATS is a message bus: clients publish messages to subjects and subscribe to subjects (with `*` and `>` wildcards), ask for a reply and get one, and through JetStream keep messages in streams that consumers read at their own pace. This library is a NATS client for all of it: a `Connection` over TCP, TLS or WebSockets, request-reply and batched requests, headers, JetStream streams and consumers, a key-value store, an object store, and NATS micro services. Everything is async, so the examples run with `utopia_async::run`; they connect to `NATS_URL` (`nats://127.0.0.1:4222` by default) and skip when nothing answers there. This guide covers the common tasks.

## Publish and subscribe

`subscribe` without a callback queues messages for `next_message`; `publish` sends one. One owner drives a connection: every call takes `&mut self`, and messages are read by the calls that read (`next_message`, `process_message`, `wait`, requests).

```
use std::time::Duration;
use utopia_nats::{Connection, ConnectionOptions, Headers};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };

    let orders = connection.subscribe("guide.orders.*", None, None).await?;
    let mut headers = Headers::new();
    headers.set("Content-Type", "application/json");
    connection.publish("guide.orders.new", r#"{"id":1}"#, None, Some(&headers)).await?;

    let message = connection.next_message(&orders, Some(Duration::from_secs(1))).await?.expect("a message");
    assert_eq!(message.subject, "guide.orders.new");
    assert_eq!(message.headers.unwrap().get("Content-Type"), Some("application/json"));
    connection.close().await?;
    Ok::<_, utopia_nats::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Headers;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$orders = $connection->subscribe('guide.orders.*');
$connection->publish('guide.orders.new', '{"id":1}', headers: (new Headers())->set('Content-Type', 'application/json'));
echo $orders->nextMessage(1.0)->subject, "\n"; // guide.orders.new
$connection->close();
```

## Reply to requests

A callback receives each message as the connection reads it. Callbacks cannot hold the connection, so one that answers keeps a `Publisher`: what it publishes goes out as soon as the callback returns, with every check `publish` makes.

```
use std::time::Duration;
use utopia_nats::{Connection, ConnectionOptions, Message};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };

    let replies = connection.publisher();
    let double = Box::new(move |msg: Message| {
        let value: i64 = std::str::from_utf8(&msg.data)?.parse()?;
        replies.publish(msg.reply_to.unwrap_or_default(), (value * 2).to_string(), None, None);
        Ok(())
    });
    connection.subscribe("guide.math.double", Some(double), None).await?;

    let answer = connection.request("guide.math.double", "21", Some(Duration::from_secs(2)), None).await?;
    assert_eq!(answer.data, "42");
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$connection->subscribe('guide.math.double', function ($msg) use ($connection) {
    $connection->publish($msg->replyTo, (string) ((int) $msg->data * 2));
});
echo $connection->request('guide.math.double', '21', 2.0)->data, "\n"; // 42
```

## Send requests together

`request_batch` writes every request at once and calls back with each request's index and outcome as replies arrive, under one deadline. A request nobody listens to fails on its own with `NoResponders`; the others still succeed. `request_many` sends one request and gathers every reply.

```
use std::time::Duration;
use utopia_nats::{Connection, ConnectionOptions, Error, Request, RequestMany};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
    let echo = connection.publisher();
    connection
        .subscribe("guide.echo", Some(Box::new(move |m| {
            echo.publish(m.reply_to.unwrap_or_default(), m.data, None, None);
            Ok(())
        })), None)
        .await?;

    let requests = [Request::new("guide.echo", "one", None)?, Request::new("guide.nobody", "two", None)?];
    let mut outcomes = Vec::new();
    connection
        .request_batch(&requests, |index, outcome| {
            outcomes.push((index, outcome.map(|m| m.data)));
            Ok(())
        }, Some(Duration::from_secs(2)))
        .await?;
    outcomes.sort_by_key(|(index, _)| *index);
    assert_eq!(outcomes[0].1.as_ref().unwrap(), "one");
    assert!(matches!(outcomes[1].1, Err(Error::NoResponders(_))));

    let replies = connection.request_many("guide.echo", "all", RequestMany { timeout: Some(Duration::from_millis(300)), ..Default::default() }).await?;
    assert_eq!(replies.len(), 1);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Message;
use Utopia\NATS\Request;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$connection->subscribe('guide.echo', fn ($m) => $connection->publish($m->replyTo, $m->data));
$connection->requestBatch([new Request('guide.echo', 'one'), new Request('guide.nobody', 'two')], function (int $index, Message|\Throwable $outcome) {
    echo $index, ': ', $outcome instanceof Message ? $outcome->data : $outcome::class, "\n";
}, 2.0);
echo count($connection->requestMany('guide.echo', 'all', ['timeout' => 0.3])), "\n"; // 1
```

## Keep an idle connection alive

The connection sends keepalive PINGs only from calls that also read, and recycles a connection whose server stopped answering them. A holder that never reads, such as a pooled publisher, calls `tick` on an interval shorter than the server's ping deadline.

```
use std::time::Duration;
use utopia_nats::{Connection, ConnectionOptions};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ping_interval: Duration::from_secs(20), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
    connection.publish("guide.events", "e1", None, None).await?;
    connection.tick().await?;
    assert!(connection.is_connected());
    Ok::<_, utopia_nats::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2, pingInterval: 20.0));
} catch (ConnectionException) {
    return;
}
$connection->publish('guide.events', 'e1');
$connection->tick();
var_dump($connection->isConnected()); // true
```

## Keep messages in a stream

A JetStream stream stores what is published to its subjects. `JetStream::publish` waits for the stream's acknowledgement (with deduplication by message id); a pull consumer fetches batches, and each message is acknowledged on its own.

```
use std::time::Duration;
use utopia_nats::jetstream::{ConsumerConfig, PublishOptions, StorageType, StreamConfig};
use utopia_nats::{Connection, ConnectionOptions};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
    let js = connection.jetstream(None, None);
    let _ = js.delete_stream(&mut connection, "GUIDE_ORDERS").await;

    let config = StreamConfig { subjects: vec!["guide.js.>".into()], storage: StorageType::Memory, duplicate_window: Some(60.0), ..StreamConfig::new("GUIDE_ORDERS") };
    js.create_stream(&mut connection, &config).await?;
    let once = PublishOptions { msg_id: Some("order-1".into()), ..Default::default() };
    assert!(!js.publish(&mut connection, "guide.js.new", b"{\"id\":1}", once.clone(), 0).await?.duplicate);
    assert!(js.publish(&mut connection, "guide.js.new", b"{\"id\":1}", once, 0).await?.duplicate);

    let consumer = js.create_consumer(&mut connection, "GUIDE_ORDERS", &ConsumerConfig { durable_name: Some("worker".into()), ..Default::default() }).await?;
    for message in consumer.fetch(&mut connection, 10, Some(Duration::from_secs(1)), false, None).await? {
        assert_eq!(message.metadata()?.stream_sequence, 1);
        message.ack(&mut connection).await?;
    }
    js.delete_stream(&mut connection, "GUIDE_ORDERS").await?;
    Ok::<_, utopia_nats::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\StreamConfig;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$js = $connection->jetStream();
$js->createStream(new StreamConfig(name: 'GUIDE_ORDERS', subjects: ['guide.js.>'], storage: StorageType::Memory, duplicateWindow: 60.0));
$js->publish('guide.js.new', '{"id":1}', msgId: 'order-1');
$consumer = $js->createConsumer('GUIDE_ORDERS', new ConsumerConfig(durableName: 'worker'));
foreach ($consumer->fetch(10, 1.0) as $message) {
    $message->ack();
}
$js->deleteStream('GUIDE_ORDERS');
```

## Store keys and objects

A key-value bucket keeps revisions of each key, with compare-and-set writes. An object store splits large values into chunks and verifies them on read.

```
use std::time::Duration;
use utopia_nats::key_value::KeyValueConfig;
use utopia_nats::object_store::{ObjectStore, ObjectStoreConfig};
use utopia_nats::{Connection, ConnectionOptions, Error};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };
    let js = connection.jetstream(None, None);

    let kv = js.create_key_value(&mut connection, &KeyValueConfig { history: 5, ..KeyValueConfig::new("guide_config") }).await?;
    kv.put(&mut connection, "app.name", "My Service").await?;
    let revision = kv.get(&mut connection, "app.name").await?.revision;
    kv.update(&mut connection, "app.name", "Renamed", revision).await?;
    assert!(matches!(kv.update(&mut connection, "app.name", "Stale", revision).await, Err(Error::KeyValue(_))));
    js.delete_key_value(&mut connection, "guide_config").await?;

    let store = ObjectStore::create_or_update(&mut connection, &js, &ObjectStoreConfig::new("guide_files")).await?;
    let meta = store.put(&mut connection, "report.bin", &vec![7u8; 300 * 1024]).await?;
    assert_eq!(meta.chunks, 3);
    assert_eq!(store.get(&mut connection, "report.bin").await?.len(), 300 * 1024);
    js.delete_object_store(&mut connection, "guide_files").await?;
    Ok::<_, Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\ObjectStore\ObjectStore;
use Utopia\NATS\ObjectStore\ObjectStoreConfig;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$js = $connection->jetStream();
$kv = $js->createKeyValue(new KeyValueConfig(bucket: 'guide_config', history: 5));
$kv->put('app.name', 'My Service');
$kv->update('app.name', 'Renamed', $kv->get('app.name')->revision);
$js->deleteKeyValue('guide_config');

$store = ObjectStore::createOrUpdate($connection, $js, new ObjectStoreConfig(bucket: 'guide_files'));
echo $store->put('report.bin', str_repeat("\x07", 300 * 1024))->chunks, "\n"; // 3
$js->deleteObjectStore('guide_files');
```

## Run a service

A `Service` answers requests on its endpoints and the `$SRV.PING`, `$SRV.INFO` and `$SRV.STATS` discovery subjects, counting requests and errors. A handler returns the reply, or an error: `Error::Service` replies with its own error code.

```
use std::time::Duration;
use bytes::Bytes;
use utopia_nats::services::Service;
use utopia_nats::{Connection, ConnectionOptions, Error};

utopia_async::Runtime::new()?.block_on(async {
    let url = std::env::var("NATS_URL").unwrap_or("nats://127.0.0.1:4222".into());
    let options = ConnectionOptions { connect_timeout: Duration::from_millis(200), ..ConnectionOptions::new([url]) };
    let Ok(mut connection) = Connection::connect(options).await else { return Ok(()) };

    let service = Service::new("guide_math", "1.0.0", "Arithmetic", Default::default());
    let math = service.add_group("guide_math", None);
    math.add_endpoint(&mut connection, "add", Box::new(|m| Ok(Bytes::from(format!("sum:{}", String::from_utf8_lossy(&m.data))))), None, None, Default::default()).await?;
    math.add_endpoint(&mut connection, "teapot", Box::new(|_| Err(Box::new(Error::Service { code: "418".into(), message: "I am a teapot".into() }))), None, None, Default::default()).await?;
    service.start(&mut connection).await?;

    assert_eq!(connection.request("guide_math.add", "2", None, None).await?.data, "sum:2");
    let refused = connection.request("guide_math.teapot", "", None, None).await?;
    assert_eq!(refused.headers.unwrap().get("Nats-Service-Error-Code"), Some("418"));
    service.stop(&mut connection).await;
    Ok::<_, Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Message;
use Utopia\NATS\Services\Service;
use Utopia\NATS\Services\ServiceException;

try {
    $connection = Connection::connect(new ConnectionOptions(servers: getenv('NATS_URL') ?: 'nats://127.0.0.1:4222', connectTimeout: 0.2));
} catch (ConnectionException) {
    return;
}
$service = new Service($connection, 'guide_math', '1.0.0', 'Arithmetic');
$math = $service->addGroup('guide_math');
$math->addEndpoint('add', fn (Message $m): string => 'sum:' . $m->data);
$math->addEndpoint('teapot', fn (Message $m): string => throw new ServiceException('418', 'I am a teapot'));
$service->start();
echo $connection->request('guide_math.add', '2')->data, "\n"; // sum:2
$service->stop();
```

## Test without a server

Anything implementing `Transport` can carry a connection: `transport_factory` hands it to every connection attempt. A scripted transport drives the whole client in a test, as the PHP tests' `FakeTransport` does.

```
use std::sync::{Arc, Mutex};
use std::time::Duration;
use utopia_nats::transport::{TlsOptions, Transport};
use utopia_nats::{Connection, ConnectionOptions, Error};

/// Serves an INFO line, answers each PING with a PONG, records writes.
#[derive(Clone, Default)]
struct Script(Arc<Mutex<(Vec<u8>, Vec<u8>)>>);

#[async_trait::async_trait]
impl Transport for Script {
    async fn connect(&mut self, _: &str, _: u16, _: Duration) -> utopia_nats::Result<()> {
        self.0.lock().unwrap().0.extend_from_slice(b"INFO {\"server_id\":\"test\",\"headers\":true}\r\n");
        Ok(())
    }
    async fn write(&mut self, data: &[u8]) -> utopia_nats::Result<usize> {
        let mut state = self.0.lock().unwrap();
        state.1.extend_from_slice(data);
        if data == b"PING\r\n" {
            state.0.extend_from_slice(b"PONG\r\n");
        }
        Ok(data.len())
    }
    async fn read(&mut self, buf: &mut Vec<u8>, max: usize, _: Option<Duration>) -> utopia_nats::Result<usize> {
        let mut state = self.0.lock().unwrap();
        if state.0.is_empty() {
            return Err(Error::Timeout("No inbound data".into()));
        }
        let n = max.min(state.0.len());
        buf.extend(state.0.drain(..n));
        Ok(n)
    }
    async fn read_line(&mut self, _: Option<Duration>) -> utopia_nats::Result<Vec<u8>> {
        Err(Error::Timeout("unused".into()))
    }
    async fn upgrade_tls(&mut self, _: &TlsOptions) -> utopia_nats::Result<()> {
        Ok(())
    }
    fn is_connected(&self) -> bool {
        true
    }
    async fn close(&mut self) {}
}

utopia_async::Runtime::new()?.block_on(async {
    let script = Script::default();
    let transport = script.clone();
    let options = ConnectionOptions {
        transport_factory: Some(Arc::new(move |_scheme: &str| Ok(Box::new(transport.clone()) as Box<dyn Transport>))),
        ..ConnectionOptions::default()
    };
    let mut connection = Connection::connect(options).await?;
    connection.publish("orders", "hi", None, None).await?;
    assert!(script.0.lock().unwrap().1.ends_with(b"PUB orders 2\r\nhi\r\n"));
    Ok::<_, Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Transport\Transport;

$transport = new class implements Transport {
    public string $inbound = '';
    public string $written = '';
    public function connect(string $host, int $port, float $timeout): void { $this->inbound .= "INFO {\"server_id\":\"test\",\"headers\":true}\r\n"; }
    public function write(string $data): int { $this->written .= $data; if ($data === "PING\r\n") { $this->inbound .= "PONG\r\n"; } return strlen($data); }
    public function read(int $maxBytes, ?float $timeout = null): string { $chunk = substr($this->inbound, 0, $maxBytes); $this->inbound = substr($this->inbound, strlen($chunk)); return $chunk; }
    public function readLine(?float $timeout = null): string { return ''; }
    public function upgradeTls(array $options): void {}
    public function isConnected(): bool { return true; }
    public function close(): void {}
};
$connection = Connection::connect(new ConnectionOptions(transportFactory: fn () => $transport));
$connection->publish('orders', 'hi');
var_dump(str_ends_with($transport->written, "PUB orders 2\r\nhi\r\n")); // true
```

## Why the protocol is implemented here

The workspace already depends on `async-nats`, the NATS maintainers' client, for the queue broker. This crate does not wrap it: what the PHP client does on the wire is part of its contract, and `async-nats` does it differently. The PHP client sends `lang: php` with its own `CONNECT` fields, writes a batch of requests as one write after one inbox subscription, answers a server `PING` inline, never replays a write that may have reached the server, reports a frame cut short as a desynced connection rather than a timeout, maps each `-ERR` onto its own exception and message, and drives keepalives only from calls that read. Callers, other services and the compat tests observe all of these (byte for byte, through a scripted transport). A native implementation on utopia-async matches them exactly, keeps one owner per connection without a background task, and costs a few thousand lines; it also lets tests swap the transport, which `async-nats` does not allow.
