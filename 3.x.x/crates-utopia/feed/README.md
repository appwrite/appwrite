# utopia-feed

CloudEvents event feeds for Utopia. Rust port of [utopia-php/feed](https://github.com/utopia-php/feed) (`ff6c011b0a8a`).

A feed is an append-only log of CloudEvents. [`Producer`](src/producer.rs) publishes; [`Consumer`](src/consumer.rs) polls and checkpoints a [`Cursor`](src/cursor/mod.rs); [`Server`](src/server.rs) serves the [HTTP Feeds](https://www.http-feeds.org/) query shape; [`Remote`](src/remote.rs) reads another process's feed over HTTP.

## Install

```toml
utopia-feed = { path = "../utopia-feed" } # workspace
```

Depends on `utopia-cache`, `utopia-pools`, and `utopia-cloudevents`.

## Usage

```rust
use serde_json::json;
use std::sync::Arc;
use utopia_feed::{Consumer, MemoryCursor, MemoryStore, Producer};

let store = MemoryStore::new("edge").unwrap();
let producer = Producer::new(store.clone(), "urn:example:edge").unwrap();
producer.produce("io.example.invalidate", json!({"host": "a.com"}), "").unwrap();

let consumer = Consumer::new(
    Arc::new(store),
    Arc::new(MemoryCursor::new()),
    "invalidator",
).unwrap();
consumer.consume_any(|event| {
    println!("{} {}", event.id, event.r#type);
}).unwrap();
```

## API Reference

| Type | PHP | Notes |
|------|-----|-------|
| `Producer` | `Utopia\Feed\Producer` | Replaces `source`, store-assigns `id`, fills missing `time`. |
| `Consumer` | `Utopia\Feed\Consumer` | `START_OLDEST` / `START_TIP`, exact `false` = unprocessed, compare-and-set `advance`. |
| `Server` | `Utopia\Feed\Server` | `serve(query)` reads `lastEventId` / `limit` / `timeout`. |
| `Remote` | `Utopia\Feed\Remote` | GET + `Accept: application/cloudevents-batch+json`. `{}` vs `[]` distinguished. |
| `Batch` | `Utopia\Feed\Batch` | Full non-empty batch → immutable `Cache-Control`. |
| `Id` | `Utopia\Feed\Id` | `/^(\d+)-(\d+)$/`; compare decoded tuples. |
| `Key` | `Utopia\Feed\Key` | `%` → `%25`, `:` → `%3A`. |
| `MemoryStore` / `CacheStore` / `RedisStore` / `PoolStore` / `NoneStore` | `Store\*` | Cache `MAX_SIZE=1000`, TTL 30 days, tip written first. |
| Matching cursors | `Cursor\*` | None cursor is a no-op that still validates names. |

`Readable::TIP` is `$`. `MAX_BATCH` 1000, `MAX_TIMEOUT` 30000 ms.

## Deviations

- HTTP for `Remote` uses [`utopia-client`](../utopia-client) (`Utopia\Client\Adapter`). Tests inject [`RecordingTransport`](src/http.rs), a scripted adapter.
- Redis / Pool backends are behind the `redis` feature; CI runs them against the compose Redis container.
- `Consumer` takes `Arc<dyn Readable>` / `Arc<dyn Cursor>` instead of PHP object handles.
- Handler `false` is `consume(|e| false)`; PHP “return nothing” is `consume_any`.

## Tests

```bash
cargo test -p utopia-feed
```

Redis/Pool live tests (`--features redis`) always hit compose Redis (`REDIS_URL`, default `redis://127.0.0.1:6379/`).

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/feed/Cargo.toml
```
