# worker-executions

The `v1-executions` queue's worker in Rust. It writes function and site execution snapshots to ClickHouse, the rows `Appwrite\Execution\Store` reads, in place of the PHP `executions` worker.

It reads the queue through `utopia-queue`, which consumes NATS JetStream only, so it serves an installation whose `_APP_CONNECTIONS_QUEUE_EXECUTIONS` is a `nats://` DSN. It shares the queue's `worker` consumer with the PHP worker, so either can take any message and they can run side by side during a switch.

Every message one receive takes (up to 500) is written in one insert and acknowledged once ClickHouse has stored it. An insert ClickHouse refuses is retried on the queue's backoff. A message that holds no execution is dead-lettered at once.

## Configuration

| Variable | Default | |
| --- | --- | --- |
| `_APP_CONNECTIONS_QUEUE_EXECUTIONS` | required | The queue's `nats://` DSN |
| `_APP_EXECUTIONS_QUEUE_NAME` | `v1-executions` | |
| `_APP_CONNECTIONS_DB_EXECUTIONS` | `_APP_CONNECTIONS_DB_USAGE` | ClickHouse DSN, `http://user:password@host:8123/database[?secure=true]` |
| `_APP_MAINTENANCE_RETENTION_EXECUTION` | `1209600` | Seconds a snapshot is kept |
| `_APP_REGION`, `_APP_SLOW_REQUEST_THRESHOLD_SECONDS` | | Span attributes and sampling, as the PHP workers' |

## Development

```bash
cargo test -p worker-executions
```

The queue test needs JetStream:

```bash
docker run -d --name nats -p 4222:4222 nats:2.12-alpine --jetstream
NATS_URL=nats://localhost:4222 cargo test -p worker-executions -- --include-ignored
```

`cargo build --release -p worker-executions` builds the binary. There is no image of its own yet: appwrite-labs/cloud builds it into its Rust worker image.
