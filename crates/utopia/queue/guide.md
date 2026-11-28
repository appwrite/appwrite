A worker in Rust takes messages from the same NATS JetStream queue as the `utopia-php/queue` workers, at the same time, and settles them the way `Broker\Nats` does. A queue can move to Rust one replica at a time. This guide covers the common tasks.

## Work a queue

Connect with the queue's `nats://` DSN and name the queue. Hold the messages while the work runs, so none goes back to another worker before it ends, then commit what was done and reject what failed. A rejected message comes back on the queue's backoff.

```
use std::time::Duration;
use utopia_queue::Worker;

# async fn run() -> utopia_queue::Result<()> {
let worker = Worker::connect("nats://user:password@nats:4222", "v1-analytics").await?;

loop {
    let deliveries = worker.receive(100, Duration::from_secs(5)).await?;
    let held: Vec<_> = deliveries.iter().collect();
    let written = worker.hold(&held, async { /* write each delivery.envelope.payload */ true }).await;

    for delivery in deliveries {
        if written {
            worker.commit(delivery).await?;
        } else {
            worker.reject(delivery, false).await?;
        }
    }
}
# }
```

```php
use Utopia\DSN\DSN;
use Utopia\Queue;
use Utopia\Queue\Broker\Nats;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;

$consumer = fn (): Consumer => Nats::fromDSN(new DSN('nats://user:password@nats:4222'), 'worker-analytics');
$server = new Queue\Server(new Queue\Adapter\Swoole($consumer, workerNum: 1));

$server
    ->job('v1-analytics', 6)
    ->inject('message')
    ->action(function (Message $message) {
        // Write $message->getPayload(). A throw rejects it.
    });

$server->start();
```

## Give up on a message

A failure no retry can fix, such as a payload naming something that does not exist, goes straight to the dead stream instead of spending its retries. `retry()` in PHP requeues it once the fault is fixed.

```
use utopia_queue::{Delivery, Worker};

# async fn settle(worker: &Worker, delivery: Delivery) -> utopia_queue::Result<()> {
worker.reject(delivery, true).await?;
# Ok(())
# }
```

```php
use Utopia\Queue\PermanentFailure;

throw new PermanentFailure('The region is not configured');
```

## What stays in PHP

The worker joins the `worker` consumer that `Broker\Nats` provisions, and waits for it if it starts first. It reads the redelivery budget and backoff from that consumer. Dead letters land on the same subjects with the same headers, so PHP's `retry()` and failed counts see them. A message that cannot be decoded, or that reaches the spare delivery `Broker\Nats` provisions, is dead-lettered on arrival.

Provisioning, the max-deliveries advisory and `reap()` stay with the PHP workers.
