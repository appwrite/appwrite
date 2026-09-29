# nats.php

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/nats`](https://github.com/utopia-php/monorepo/tree/main/packages/nats) — please open issues and pull requests there.

A modern PHP client for [NATS](https://nats.io) messaging system with JetStream and Key-Value store support.

## Requirements

- PHP 8.1+
- `ext-json`
- `ext-sodium` (optional, for NKey/JWT authentication)

## Installation

```bash
composer require utopia-php/nats
```

## Quick start

```php
use Utopia\NATS\Connection;

$conn = Connection::connect('nats://127.0.0.1:4222');

// Publish
$conn->publish('greet.world', 'Hello, World!');

// Subscribe
$conn->subscribe('greet.*', function ($msg) {
    echo "Received: {$msg->data}\n";
});

// Process messages
$conn->wait();
```

## Core NATS

### Connecting

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;

// Simple
$conn = Connection::connect('nats://127.0.0.1:4222');

// With options
$conn = Connection::connect(new ConnectionOptions(
    servers: ['nats://host1:4222', 'nats://host2:4222'],
    name: 'my-service',
    user: 'alice',
    pass: 'secret',
    connectTimeout: 5.0,
    allowReconnect: true,
    maxReconnectAttempts: 60,
));

// From URL with credentials
$conn = Connection::connect('nats://user:pass@127.0.0.1:4222');
```

### Publishing

```php
use Utopia\NATS\Headers;

// Simple publish
$conn->publish('orders.new', '{"id": 1}');

// Publish with headers
$headers = new Headers();
$headers->set('Content-Type', 'application/json');
$headers->set('X-Trace-Id', 'abc-123');
$conn->publish('orders.new', '{"id": 1}', headers: $headers);
```

### Subscribing

```php
// Async with callback
$sub = $conn->subscribe('orders.*', function ($msg) {
    echo "{$msg->subject}: {$msg->data}\n";
});

// Sync
$sub = $conn->subscribe('orders.new');
$msg = $sub->nextMessage(timeout: 5.0);

// Wildcards
$conn->subscribe('events.>',  fn($msg) => handle($msg)); // multi-level
$conn->subscribe('orders.*',  fn($msg) => handle($msg)); // single-level

// Queue groups (load-balanced)
$conn->queueSubscribe('tasks', 'workers', function ($msg) {
    processTask($msg->data);
});

// Unsubscribe
$sub->unsubscribe();

// Auto-unsubscribe after N messages
$conn->unsubscribe($sub, maxMessages: 10);
```

### Request-reply

```php
// Responder
$conn->subscribe('math.double', function ($msg) use ($conn) {
    $value = (int) $msg->data;
    $conn->publish($msg->replyTo, (string) ($value * 2));
});

// Requester
$response = $conn->request('math.double', '21', timeout: 2.0);
echo $response->data; // "42"
```

### Connection management

```php
$conn->flush();                    // Ensure all messages are sent
$conn->drain();                    // Gracefully close (flush + unsubscribe)
$conn->close();                    // Immediate close

$conn->isConnected();              // Check status
$conn->getServerInfo()->version;   // Server info
```

## JetStream

### Streams

```php
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\RetentionPolicy;

$js = $conn->jetStream();

// Create a stream
$stream = $js->createOrUpdateStream(new StreamConfig(
    name: 'ORDERS',
    subjects: ['orders.>'],
    storage: StorageType::File,
    retention: RetentionPolicy::Limits,
    maxAge: 86400.0, // 1 day in seconds
    replicas: 1,
));

// Stream info
$info = $stream->info(refresh: true);
echo "Messages: {$info->state->messages}\n";

// List streams
$names = $js->getStreamNames();

// Delete
$stream->delete();
```

### Publishing with acknowledgment

```php
$ack = $js->publish('orders.new', '{"id": 1}');
echo "Stream: {$ack->stream}, Seq: {$ack->sequence}\n";

// With deduplication
$ack = $js->publish('orders.new', '{"id": 1}', msgId: 'order-1');

// With expected sequence (optimistic concurrency)
$ack = $js->publish('orders.new', $data, expectedLastSeq: 42);
```

### Publishing a batch

`publish()` waits for each acknowledgment before it sends the next message, so a
batch of `N` messages costs `N` round trips. `publishMany()` writes a window of
messages first and reads their acknowledgments afterwards, which costs one round
trip per window. Each message keeps its own acknowledgment, so deduplication and
per-message errors work the same way.

```php
$acks = $js->publishMany([
    ['subject' => 'orders.new', 'data' => '{"id": 1}', 'msgId' => 'order-1'],
    ['subject' => 'orders.new', 'data' => '{"id": 2}', 'msgId' => 'order-2'],
]);

// The acknowledgments come back in the order the messages were given.
echo "Seq: {$acks[0]->sequence}\n";
```

The `window` argument sets how many messages stay in flight before their
acknowledgments are collected. A message the server rejects throws, as it does on
`publish()`.

### Consumers

```php
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\DeliverPolicy;

// Create a pull consumer
$consumer = $js->createConsumer('ORDERS', new ConsumerConfig(
    name: 'order-processor',
    durableName: 'order-processor',
    ackPolicy: AckPolicy::Explicit,
    filterSubject: 'orders.>',
    deliverPolicy: DeliverPolicy::All,
));

// Fetch a batch of messages
$batch = $consumer->fetch(batch: 10, timeout: 5.0);
foreach ($batch as $msg) {
    echo "Processing: {$msg->getData()}\n";

    $msg->ack();          // Acknowledge
    // $msg->nak();       // Negative ack (redeliver)
    // $msg->term();      // Terminate (no redeliver)
    // $msg->inProgress(); // Extend ack deadline
}

// Fetch single message
$msg = $consumer->next(timeout: 5.0);

// Message metadata
$meta = $msg->metadata();
echo "Stream seq: {$meta->streamSequence}\n";
echo "Deliveries: {$meta->numDelivered}\n";
```

## Key-value store

```php
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\JetStream\StorageType;

$js = $conn->jetStream();

// Create a KV bucket
$kv = $js->createKeyValue(new KeyValueConfig(
    bucket: 'config',
    history: 5,
    ttl: 3600.0, // 1 hour
    storage: StorageType::File,
));

// Put
$revision = $kv->put('app.name', 'My Service');

// Get
$entry = $kv->get('app.name');
echo "{$entry->key} = {$entry->value} (rev: {$entry->revision})\n";

// Create (fails if key exists)
$revision = $kv->create('app.version', '1.0.0');

// Update with CAS (compare-and-swap)
$revision = $kv->update('app.version', '1.1.0', revision: $revision);

// Delete / Purge
$kv->delete('app.name');
$kv->purge('app.version');

// List keys
$keys = $kv->keys();

// Bucket status
$status = $kv->status();
echo "Values: {$status->values}, Bytes: {$status->bytes}\n";
```

## Authentication

```php
use Utopia\NATS\ConnectionOptions;

// User/Password
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    user: 'alice',
    pass: 'secret',
));

// Token
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    token: 'my-token',
));

// NKey (requires ext-sodium)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    nkey: 'UABC...',
    nkeySeed: 'SUABC...',
));

// JWT Credentials file (requires ext-sodium)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    credentialsFile: '/path/to/user.creds',
));
```

## TLS

```php
use Utopia\NATS\ConnectionOptions;

// TLS with system CA
$conn = Connection::connect(new ConnectionOptions(
    servers: 'tls://nats.example.com:4222',
));

// TLS with custom CA and client certificates (mTLS)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    tls: true,
    tlsCaFile: '/path/to/ca.pem',
    tlsCertFile: '/path/to/client-cert.pem',
    tlsKeyFile: '/path/to/client-key.pem',
));
```

## Event callbacks

```php
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    onDisconnect: function () {
        echo "Disconnected!\n";
    },
    onReconnect: function () {
        echo "Reconnected!\n";
    },
    onClose: function () {
        echo "Connection closed.\n";
    },
    onError: function ($e) {
        echo "Error: {$e->getMessage()}\n";
    },
));
```

## Testing

```bash
# Unit tests
./vendor/bin/phpunit --testsuite unit

# Integration tests (requires a running nats-server)
./vendor/bin/phpunit --testsuite integration

# With custom NATS URL
NATS_URL=nats://host:4222 ./vendor/bin/phpunit --testsuite integration
```

## Batched requests

`Connection::requestBatch()` sends independent requests together and delivers each outcome to `reply` as it arrives. The callback receives the original input index and either a `Message` or a `Throwable`. One missing reply does not delay successful replies or discard their outcomes.

```php
$connection->requestBatch(
    requests: [
        new Request(subject: 'service.first', data: 'one'),
        new Request(subject: 'service.second', data: 'two', headers: $headers),
    ],
    reply: function (int $index, Message|Throwable $result): void {
        // Handle this request's reply or failure.
    },
    timeout: 5.0,
);
```

`Request` has read-only typed fields; both `request()` and `requestBatch()` use its subject validation and the same request lifecycle. The method returns `void`; collect results in the callback if you need an array. Completion order can differ from input order. The timeout is one response deadline for the whole group, starting after the write, rather than a separate wait per reply. Callback execution counts toward that deadline. Empty input performs no I/O. Invalid input throws before any request is published; request headers and payload limits follow `request()`.

If the callback throws, collection stops immediately, pending state is cleaned up, and the exception propagates. Requests already sent are not cancelled or replayed. Later replies can still arrive. Use one owner for reading the connection; nested reads from the callback throw `LogicException`.

This differs from `requestMany()`, which sends one request and gathers several responses. Ambiguous writes are not replayed on reconnect. Use `JetStream::ackBatch()` to confirm a selected list of `JetStreamMessage` instances. JetStream constructs each acknowledgement; its callback receives the original index and `null` on server confirmation or a `Throwable` on failure. The consumer's configured acknowledgement policy still applies. Use `AckPolicy::Explicit` to leave messages outside the list unacknowledged. With `AckPolicy::All`, acknowledging a later message also acknowledges earlier messages, including those outside the list.

```php
$jetStream->ackBatch($messages, function (int $index, ?Throwable $error): void {
    // Null means this message's acknowledgement was confirmed.
});
```

Single `request()` calls retain one retry for an explicit stale-connection rejection. Batch requests do not retry. Neither path replays an ambiguous write.

## License

Apache-2.0
