# Utopia Cache

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/cache`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/cache) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Key-value caching with expiring entries over memory, files, Redis, Redis Cluster, Memcached and Hazelcast, with sharding, pooling, circuit breaking and purge-safe leases.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-cache = { git = "https://github.com/utopia-rs/cache" }
```

A cache keeps the results of expensive work (a database read, a rendered page, a remote call) under a key, so the next request can reuse them. This library gives every backend the same small API: `save` a value, `load` it back while it is younger than a TTL, `touch` it to make it fresh again, and `purge` it when the source changes. Backends are adapters: memory, files, Redis (plain, multiplexed, cluster), Memcached and Hazelcast, and adapters that combine others. The cache is async, so the examples run it with `utopia_async::run`. This guide covers the common tasks.

## Cache a value for a while

Wrap an adapter in a `Cache`, then save and load values. A load takes a TTL in seconds: an entry saved longer ago than that is a miss (`None`). Values are PHP values (`php_std::types::Value`): strings, numbers, arrays.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Memory};

let cache = Cache::new(Memory::new());

utopia_async::Runtime::new()?.block_on(async {
    assert!(cache.save("greeting", &Value::from("hello"), "", 0).await?);
    assert_eq!(cache.load("greeting", 60, "").await?, Some(Value::from("hello")));

    // A TTL of 0 means "older than now": a miss.
    assert_eq!(cache.load("greeting", 0, "").await?, None);
    Ok::<_, utopia_cache::Error>(())
})?;
```

## Purge an entry when its source changes

`purge` removes an entry; the next load misses and the caller rebuilds it. Keys are case-insensitive unless you ask otherwise, so `User:1` and `user:1` are the same entry.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Memory};

let cache = Cache::new(Memory::new());

utopia_async::Runtime::new()?.block_on(async {
    cache.save("User:1", &Value::from("Ada"), "", 0).await?;
    assert!(cache.purge("user:1", "").await?);
    assert_eq!(cache.load("User:1", 60, "").await?, None);

    cache.set_case_sensitivity(true);
    cache.save("Color", &Value::from("pink"), "", 0).await?;
    assert_eq!(cache.load("color", 60, "").await?, None);
    Ok::<_, utopia_cache::Error>(())
})?;
```

## Keep entries on disk

`Filesystem` stores one file per key under a directory; keys with `/` become subdirectories. The size of this cache is the bytes it holds.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Filesystem};

let dir = std::env::temp_dir().join(format!("utopia-cache-guide-{}", std::process::id()));
let cache = Cache::new(Filesystem::new(&dir));

utopia_async::Runtime::new()?.block_on(async {
    cache.save("pages/home", &Value::from("<h1>Home</h1>"), "", 0).await?;
    assert_eq!(cache.load("pages/home", 3600, "").await?, Some(Value::from("<h1>Home</h1>")));
    assert_eq!(cache.size().await?, 13);
    Ok::<_, utopia_cache::Error>(())
})?;
std::fs::remove_dir_all(&dir)?;
```

## Cache Rust-only data natively

A cache shared with a PHP runtime keeps PHP's codec; a cache only Rust reads uses the native codec. `save_native` and `load_native` store any `serde` type as a compact binary entry, with the same TTL, leases and purges as other entries, and without building PHP values. Keep such entries in fields PHP never reads (a purge of the key still drops them), and change the field when the type changes: the format carries no field names.

The native format is [postcard](https://docs.rs/postcard) behind a three-byte header (`0xF5`, `N`, the format version) and the save time. Postcard was picked on numbers: on a user document it encodes about six times and decodes about twice as fast as PHP's igbinary, and its entries are smaller. It is maintained, pure Rust, has a documented stable wire format, and fails cleanly on truncated or hostile input: every length is checked against the bytes left, so a corrupt entry is a miss, never a panic or an oversized allocation. bincode is no longer maintained, bitcode's derive cannot encode recursive types such as JSON trees, and rkyv needs its own derives and an aligned copy of each entry before reading it.

```rust
use serde::{Deserialize, Serialize};
use utopia_cache::{Cache, Memory};

#[derive(Debug, PartialEq, Serialize, Deserialize)]
struct Events {
    names: Vec<String>,
}

let cache = Cache::new(Memory::new());

utopia_async::Runtime::new()?.block_on(async {
    let events = Events { names: vec!["users.*.create".into()] };
    let generation = cache.generation("project:1").await?;
    assert!(cache.save_native_with_lease("project:1", &events, "rust:events:v1", &generation).await?);
    assert_eq!(cache.load_native::<Events>("project:1", 3600, "rust:events:v1").await?, Some(events));
    Ok::<_, utopia_cache::Error>(())
})?;
```

## Store several fields under one key

Hash-based adapters keep many fields under one key. `save_many` writes them in one round trip and `load_many` reads some or all of them; a TTL greater than 0 also gives the whole key an expiry.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Fields, Redis};

utopia_async::Runtime::new()?.block_on(async {
    let client = redis::Client::open("redis://localhost:6379")?;
    let cache = Cache::new(Redis::new(client.get_connection_manager().await?));

    let mut topics = Fields::new();
    topics.insert("topica".into(), Value::Int(5));
    topics.insert("topicb".into(), Value::Int(9));
    cache.save_many("guide:sequences", &topics, 300).await?;

    let some = cache.load_many("guide:sequences", 3600, &["topica", "missing"]).await?;
    assert_eq!(some.len(), 1);
    cache.purge("guide:sequences", "").await?;
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Refuse stale writes after a purge

A reader that loads from the database while a writer purges the cache could put the old row back. Read the key's generation before the database read and save with `save_with_lease`: the save is refused when a purge happened in between.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Redis};

utopia_async::Runtime::new()?.block_on(async {
    let client = redis::Client::open("redis://localhost:6379")?;
    let cache = Cache::new(Redis::new(client.get_connection_manager().await?));

    let generation = cache.generation("guide:doc").await?;
    // ... read the document from the database, while a writer purges it ...
    cache.purge("guide:doc", "").await?;
    assert!(!cache.save_with_lease("guide:doc", &Value::from("stale"), "", &generation).await?);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
```

## Spread keys over several backends

`Sharding` picks one adapter per key from a hash of the key, so each key always lives on the same shard. Flushes and sizes cover every shard.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, Memory, Sharding};

let cache = Cache::new(Sharding::new(vec![Memory::new(), Memory::new()])?);

utopia_async::Runtime::new()?.block_on(async {
    for key in ["a", "b", "c"] {
        cache.save(key, &Value::from(key), "", 0).await?;
    }
    assert_eq!(cache.size().await?, 3);
    Ok::<_, utopia_cache::Error>(())
})?;
```

## Keep serving when the cache is down

A `CircuitBreaker` adapter turns failures of the backend into misses and `false`, and stops calling a backend that keeps failing until it recovers. The circuit's settings decide how many failures open it.

```rust
use php_std::types::Value;
use utopia_cache::{Cache, CircuitBreaker, Memory};
use utopia_circuit_breaker::{CircuitBreaker as Breaker, Options};

let breaker = Breaker::new(Options { minimum_throughput: 5, ..Options::default() })?;
let cache = Cache::new(CircuitBreaker::new(Memory::new(), breaker));

utopia_async::Runtime::new()?.block_on(async {
    cache.save("key", &Value::from("value"), "", 0).await?;
    assert_eq!(cache.load("key", 60, "").await?, Some(Value::from("value")));
    Ok::<_, utopia_cache::Error>(())
})?;
```

## Read the stored format

The hash-based adapters store an envelope: the value and the time it was written, encoded as JSON by default or igbinary. `Envelope` encodes and checks them directly, with the clock passed in.

```rust
use php_std::types::Value;
use utopia_cache::{Envelope, Igbinary, Json};

let json = Envelope::new(Json);
let stored = json.encode(&Value::from("hello"), 100)?;
assert_eq!(stored, br#"{"time":100,"data":"hello"}"#);
assert_eq!(json.decode(&stored, 60, 159), Some(Value::from("hello")));
assert_eq!(json.decode(&stored, 60, 160), None);

// Another codec's bytes are a miss, never an error.
assert_eq!(Envelope::new(Igbinary).decode(&stored, 60, 130), None);
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
