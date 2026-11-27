# Utopia Queue

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Queue workers that share their NATS JetStream queues with [utopia-php/queue](https://github.com/utopia-php/queue).

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-queue = { git = "https://github.com/appwrite/appwrite" }
```

A worker in Rust takes messages from the same NATS JetStream queue as the `utopia-php/queue` workers, at the same time, and settles them the way `Broker\Nats` does. A queue can move to Rust one replica at a time.

## Work a queue

Connect with the queue's `nats://` DSN and name the queue. Hold the messages while the work runs, so none goes back to another worker before it ends, then commit what was done and reject what failed. A rejected message comes back on the queue's backoff.

```rust
use std::time::Duration;
use utopia_queue::Worker;

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
```

## Give up on a message

A failure no retry can fix, such as a payload naming something that does not exist, goes straight to the dead stream instead of spending its retries. `retry()` in PHP requeues it once the fault is fixed.

```rust
worker.reject(delivery, true).await?;
```

## What stays in PHP

The worker joins the `worker` consumer that `Broker\Nats` provisions, and waits for it if it starts first. It reads the redelivery budget and backoff from that consumer. Dead letters land on the same subjects with the same headers, so PHP's `retry()` and failed counts see them. A message that cannot be decoded, or that reaches the spare delivery `Broker\Nats` provisions, is dead-lettered on arrival.

Provisioning, the max-deliveries advisory and `reap()` stay with the PHP workers.

## Contributing

Development happens in [appwrite/appwrite](https://github.com/appwrite/appwrite), where this crate lives at [`crates/utopia/queue`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/queue). Run these commands from a checkout of it. The [contributing guide](https://utopia-docs.appwrite.network/contributing) covers each step.

### Test

```bash
cargo test -p utopia-queue
cargo clippy -p utopia-queue --all-targets -- -D warnings
cargo fmt --all --check
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
