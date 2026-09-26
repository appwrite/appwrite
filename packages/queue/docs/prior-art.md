# Queue batching: prior art

Research for [Cloud #5996](https://github.com/appwrite-labs/cloud/issues/5996), 2026-09-21. These are source-level findings, not benchmarks of our adapters.

## Official NATS client

The [official pull example](https://examples.nats.io/examples/jetstream/pull-consumer/go/) fetches multiple messages and acknowledges each individually. Its channel yields arriving messages before the batch is complete; closing the channel waits for the requested count or timeout. A materialized PHP array cannot provide that same incremental delivery.

The [Go client](https://github.com/nats-io/nats.go/blob/7a8404ab9b1721cf1eddf3a26474e6925c322d73/jetstream/README.md#L503) supports bounded prefetch and threshold-based refill through `PullMaxMessages` and `PullThresholdMessages`. Fetch capacity need not equal the number of coroutines processing messages.

Ordinary acknowledgements use publish, with [socket writes coalesced by the connection](https://github.com/nats-io/nats.go/blob/7a8404ab9b1721cf1eddf3a26474e6925c322d73/nats.go#L4173). Thus 100 acknowledgement frames need not mean 100 blocking round trips. [DoubleAck instead performs a request/reply](https://github.com/nats-io/nats.go/blob/7a8404ab9b1721cf1eddf3a26474e6925c322d73/jetstream/message.go#L435); the [official confirmed-ack example](https://examples.nats.io/examples/jetstream/ack-ack/go/) explains the stronger confirmation guarantee.

Borrow bounded prefetch, incremental delivery, shared connection buffering, and reply correlation. Overlapping multiple confirmed acknowledgements is our proposed adaptation to retain the current guarantee, not a documented bulk-ack API. Do not infer that canonical NATS consumption requires grouped completion or `AckAll`.

## Redis lists: official primitive

[Redis documents](https://redis.io/docs/latest/commands/lmove/) the reliable queue pattern: atomically move a message from ready to processing with `LMOVE`/`BLMOVE`, remove it after success, and recover abandoned processing entries. This primitive moves one entry. There is no count-based reliable move-and-claim primitive; a destructive batch pop alone is insufficient.

## Dramatiq: batch fetch and recovery

[Dramatiq's Redis Lua dispatch](https://github.com/Bogdanp/dramatiq/blob/e9e7c4312308a8c7c27896c64a6f437af07dce18/dramatiq/brokers/redis/dispatch.lua#L153) fetches up to the prefetch count in one script: pop IDs from a list, record ownership in a per-worker acknowledgement set, and return payloads from a hash. Claiming and fetching happen atomically in one request.

Its [consumer](https://github.com/Bogdanp/dramatiq/blob/e9e7c4312308a8c7c27896c64a6f437af07dce18/dramatiq/brokers/redis.py#L303) counts cached and outstanding messages against prefetch capacity. Dispatch also updates worker heartbeats and maintenance requeues unacknowledged IDs belonging to dead workers. Empty queues use polling with backoff.

[Acknowledgement remains per message](https://github.com/Bogdanp/dramatiq/blob/e9e7c4312308a8c7c27896c64a6f437af07dce18/dramatiq/brokers/redis/dispatch.lua#L191): one script conditionally removes ownership and deletes the payload. This is precedent for atomic batch claiming and worker-level recovery, not evidence of bulk settlement.

## Combining transitions with BullMQ

[moveToActive](https://github.com/taskforcesh/bullmq/blob/3b74ea6257f0bf41f7d2e1784a5ba42246a781a6/src/commands/moveToActive-11.lua#L74) moves a job from waiting to active and prepares its lock within the script. [moveToFinished](https://github.com/taskforcesh/bullmq/blob/3b74ea6257f0bf41f7d2e1784a5ba42246a781a6/src/commands/moveToFinished-14.lua#L212) can complete the current job and fetch the next in the same operation. [`extendLocks`](https://github.com/taskforcesh/bullmq/blob/3b74ea6257f0bf41f7d2e1784a5ba42246a781a6/src/commands/extendLocks-1.lua) renews multiple locks with ownership checks in one script.

[BullMQ Pro batches](https://docs.bullmq.io/bullmq-pro/batches) provide multi-job callbacks, optional minimum size/timeout, and individual failure handling. Its implementation is not part of the inspected open-source code; the documentation does not establish its wire-level request counts.

## Implications for our plan

- Use the official NATS client as the transport reference and Dramatiq as the Redis batch-claim reference. Borrow BullMQ's combined transitions and grouped renewal where they simplify the lifecycle.
- Measure network round trips and broker work separately. Individual acknowledgements are compatible with efficient batch consumption; serial synchronous requests are the particular cost we need to remove.
- Keep batch size independent from coroutines and bound all outstanding deliveries.
- Dramatiq and BullMQ store IDs separately from payloads. Copying their layouts requires a Redis data-format migration even though they use lists. Our existing opaque payload entries prevent copying their claim scripts unchanged.
- The reservation/finalization design in the companion plan is a compatibility proposal, not established prior art. Compare its complexity with an explicitly versioned ID/payload migration before implementing it. Redis Streams remains out of scope.
- Do not add a completion timer merely because it sounds like batching. Validate whether buffering/pipelining individual settlements meets the objective before introducing delayed grouped settlement and its additional state.
