A cache keeps the results of expensive work (a database read, a rendered page, a remote call) under a key, so the next request can reuse them. This library gives every backend the same small API: `save` a value, `load` it back while it is younger than a TTL, `touch` it to make it fresh again, and `purge` it when the source changes. Backends are adapters: memory, files, Redis (plain, multiplexed, cluster), Memcached and Hazelcast, and adapters that combine others. The cache is async, so the examples run it on a Tokio runtime. This guide covers the common tasks.

## Cache a value for a while

Wrap an adapter in a `Cache`, then save and load values. A load takes a TTL in seconds: an entry saved longer ago than that is a miss (`None`). Values are PHP values (`php_std::types::Value`): strings, numbers, arrays.

```
use php_std::types::Value;
use utopia_cache::{Cache, Memory};

let cache = Cache::new(Memory::new());

tokio::runtime::Runtime::new()?.block_on(async {
    assert!(cache.save("greeting", &Value::from("hello"), "", 0).await?);
    assert_eq!(cache.load("greeting", 60, "").await?, Some(Value::from("hello")));

    // A TTL of 0 means "older than now": a miss.
    assert_eq!(cache.load("greeting", 0, "").await?, None);
    Ok::<_, utopia_cache::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

$cache = new Cache(new Memory());
$cache->save('greeting', 'hello');
echo $cache->load('greeting', 60), "\n"; // hello
var_dump($cache->load('greeting', 0)); // bool(false)
```

## Purge an entry when its source changes

`purge` removes an entry; the next load misses and the caller rebuilds it. Keys are case-insensitive unless you ask otherwise, so `User:1` and `user:1` are the same entry.

```
use php_std::types::Value;
use utopia_cache::{Cache, Memory};

let cache = Cache::new(Memory::new());

tokio::runtime::Runtime::new()?.block_on(async {
    cache.save("User:1", &Value::from("Ada"), "", 0).await?;
    assert!(cache.purge("user:1", "").await?);
    assert_eq!(cache.load("User:1", 60, "").await?, None);

    cache.set_case_sensitivity(true);
    cache.save("Color", &Value::from("pink"), "", 0).await?;
    assert_eq!(cache.load("color", 60, "").await?, None);
    Ok::<_, utopia_cache::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

$cache = new Cache(new Memory());
$cache->save('User:1', 'Ada');
var_dump($cache->purge('user:1')); // bool(true)
var_dump($cache->load('User:1', 60)); // bool(false)

$cache->setCaseSensitivity(true);
$cache->save('Color', 'pink');
var_dump($cache->load('color', 60)); // bool(false)
```

## Keep entries on disk

`Filesystem` stores one file per key under a directory; keys with `/` become subdirectories. The size of this cache is the bytes it holds.

```
use php_std::types::Value;
use utopia_cache::{Cache, Filesystem};

let dir = std::env::temp_dir().join(format!("utopia-cache-guide-{}", std::process::id()));
let cache = Cache::new(Filesystem::new(&dir));

tokio::runtime::Runtime::new()?.block_on(async {
    cache.save("pages/home", &Value::from("<h1>Home</h1>"), "", 0).await?;
    assert_eq!(cache.load("pages/home", 3600, "").await?, Some(Value::from("<h1>Home</h1>")));
    assert_eq!(cache.size().await?, 13);
    Ok::<_, utopia_cache::Error>(())
})?;
std::fs::remove_dir_all(&dir)?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;

$dir = sys_get_temp_dir() . '/utopia-cache-guide-' . getmypid();
$cache = new Cache(new Filesystem($dir));
$cache->save('pages/home', '<h1>Home</h1>');
echo $cache->load('pages/home', 3600), "\n"; // <h1>Home</h1>
echo $cache->getSize(), "\n"; // 13
exec('rm -rf ' . escapeshellarg($dir));
```

## Share a cache with PHP through Redis

The Redis adapter stores each entry as a field of a Redis hash, encoded exactly as the PHP library encodes it, so a PHP service and a Rust service can share one cache: either one can read, refresh or purge what the other wrote.

```no_run
use php_std::types::Value;
use utopia_cache::{Cache, Redis};

tokio::runtime::Runtime::new()?.block_on(async {
    let client = redis::Client::open("redis://localhost:6379")?;
    let cache = Cache::new(Redis::new(client.get_connection_manager().await?));

    cache.save("guide:profile", &Value::from("Ada"), "", 0).await?;
    assert_eq!(cache.load("guide:profile", 60, "").await?, Some(Value::from("Ada")));
    cache.purge("guide:profile", "").await?;
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;

$client = new \Redis();
$client->connect('redis', 6379);
$cache = new Cache(new Redis($client));

$cache->save('guide:profile', 'Ada');
echo $cache->load('guide:profile', 60), "\n"; // Ada
$cache->purge('guide:profile');
```

## Store several fields under one key

Hash-based adapters keep many fields under one key. `save_many` writes them in one round trip and `load_many` reads some or all of them; a TTL greater than 0 also gives the whole key an expiry.

```no_run
use php_std::types::Value;
use utopia_cache::{Cache, Fields, Redis};

tokio::runtime::Runtime::new()?.block_on(async {
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
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;

$client = new \Redis();
$client->connect('redis', 6379);
$cache = new Cache(new Redis($client));

$cache->saveMany('guide:sequences', ['topica' => 5, 'topicb' => 9], 300);
var_dump(count($cache->loadMany('guide:sequences', 3600, ['topica', 'missing']))); // int(1)
$cache->purge('guide:sequences');
```

## Refuse stale writes after a purge

A reader that loads from the database while a writer purges the cache could put the old row back. Read the key's generation before the database read and save with `save_with_lease`: the save is refused when a purge happened in between.

```no_run
use php_std::types::Value;
use utopia_cache::{Cache, Redis};

tokio::runtime::Runtime::new()?.block_on(async {
    let client = redis::Client::open("redis://localhost:6379")?;
    let cache = Cache::new(Redis::new(client.get_connection_manager().await?));

    let generation = cache.generation("guide:doc").await?;
    // ... read the document from the database, while a writer purges it ...
    cache.purge("guide:doc", "").await?;
    assert!(!cache.save_with_lease("guide:doc", &Value::from("stale"), "", &generation).await?);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;

$client = new \Redis();
$client->connect('redis', 6379);
$cache = new Cache(new Redis($client));

$generation = $cache->getGeneration('guide:doc');
$cache->purge('guide:doc');
var_dump($cache->saveWithLease('guide:doc', 'stale', '', $generation)); // bool(false)
$client->del('guide:doc');
```

## Spread keys over several backends

`Sharding` picks one adapter per key from a hash of the key, so each key always lives on the same shard. Flushes and sizes cover every shard.

```
use php_std::types::Value;
use utopia_cache::{Cache, Memory, Sharding};

let cache = Cache::new(Sharding::new(vec![Memory::new(), Memory::new()])?);

tokio::runtime::Runtime::new()?.block_on(async {
    for key in ["a", "b", "c"] {
        cache.save(key, &Value::from(key), "", 0).await?;
    }
    assert_eq!(cache.size().await?, 3);
    Ok::<_, utopia_cache::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;

$cache = new Cache(new Sharding([new Memory(), new Memory()]));
foreach (['a', 'b', 'c'] as $key) {
    $cache->save($key, $key);
}
echo $cache->getSize(), "\n"; // 3
```

## Keep serving when the cache is down

A `CircuitBreaker` adapter turns failures of the backend into misses and `false`, and stops calling a backend that keeps failing until it recovers. The circuit's settings decide how many failures open it.

```
use php_std::types::Value;
use utopia_cache::{Cache, Circuit, CircuitBreaker, CircuitOptions, Memory};

let breaker = Circuit::new(CircuitOptions { minimum_throughput: 5, ..CircuitOptions::default() })?;
let cache = Cache::new(CircuitBreaker::new(Memory::new(), breaker));

tokio::runtime::Runtime::new()?.block_on(async {
    cache.save("key", &Value::from("value"), "", 0).await?;
    assert_eq!(cache.load("key", 60, "").await?, Some(Value::from("value")));
    Ok::<_, utopia_cache::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Adapter\CircuitBreaker;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\CircuitBreaker\CircuitBreaker as Breaker;

$cache = new Cache(new CircuitBreaker(new Memory(), new Breaker(minimumThroughput: 5)));
$cache->save('key', 'value');
echo $cache->load('key', 60), "\n"; // value
```

## Read the stored format

The hash-based adapters store an envelope: the value and the time it was written, encoded as JSON by default or igbinary. `Envelope` encodes and checks them directly, with the clock passed in.

```
use php_std::types::Value;
use utopia_cache::{Envelope, Igbinary, Json};

let json = Envelope::new(Json);
let stored = json.encode(&Value::from("hello"), 100)?;
assert_eq!(stored, br#"{"time":100,"data":"hello"}"#);
assert_eq!(json.decode(&stored, 60, 159), Some(Value::from("hello")));
assert_eq!(json.decode(&stored, 60, 160), None);

// Another codec's bytes are a miss, never an error.
assert_eq!(Envelope::new(Igbinary).decode(&stored, 60, 130), None);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\Envelope;

$json = new Envelope(new Json());
$stored = $json->encode('hello', 100);
echo $stored, "\n"; // {"time":100,"data":"hello"}
echo $json->decode($stored, 60, 159), "\n"; // hello
var_dump($json->decode($stored, 60, 160)); // bool(false)
var_dump((new Envelope(new Igbinary()))->decode($stored, 60, 130)); // bool(false)
```
