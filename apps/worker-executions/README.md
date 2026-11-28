# worker-executions

The `v1-executions` queue's worker in Rust. It writes function and site execution snapshots to ClickHouse, the rows `Appwrite\Execution\Store` reads, in place of the PHP `executions` worker.

It reads the queue through `utopia-queue`, which consumes NATS JetStream only, so it serves an installation whose `_APP_CONNECTIONS_QUEUE_EXECUTIONS` is a `nats://` DSN. It shares the queue's `worker` consumer with the PHP worker, so either can take any message and they can run side by side during a switch.

After the first message of a batch it keeps receiving for 500 ms (or until it holds 5,000 rows), then writes the batch in one insert and acknowledges it once ClickHouse has stored it. A pod so makes at most two inserts a second, the bound ClickHouse's insert buffer (`_APP_EXECUTIONS_INSERT_BUFFER`) gives the PHP worker, which this worker does not use. A batch that fills to 5,000 rows is written at once, so a replayed backlog can insert faster than that, but each of those inserts is one large part. An insert ClickHouse refuses is retried on the queue's backoff. A message that holds no execution is dead-lettered at once.

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
cargo test --manifest-path apps/worker-executions/Cargo.toml
```

The queue test needs JetStream:

```bash
docker run -d --name nats -p 4222:4222 nats:2.12-alpine --jetstream
NATS_URL=nats://localhost:4222 cargo test --manifest-path apps/worker-executions/Cargo.toml -- --include-ignored
```

`cargo build --release --manifest-path apps/worker-executions/Cargo.toml` builds the binary. There is no image of its own yet: appwrite-labs/cloud builds it into its Rust worker image.
