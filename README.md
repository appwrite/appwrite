# Utopia Cache

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/cache`](https://github.com/utopia-php/monorepo/tree/main/packages/cache) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/cache.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia framework cache library is simple and lite library for managing application cache storing, loading and purging. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:

```bash
composer require utopia-php/cache
```

**File System Adapter**

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Filesystem;

$cache  = new Cache(new Filesystem('/cache-dir'));
$key    = 'data-from-example.com';

$data   = $cache->load($key, 60 * 60 * 24 * 30 * 3 /* 3 months */);

if(!$data) {
    $data = file_get_contents('https://example.com');
    
    $cache->save($key, $data);
}

echo $data;
```

## Adapters

| Adapter | Notes |
| --- | --- |
| `Filesystem` | Files on disk, with optional streaming reads. |
| `Memory` | In-process array, useful in tests. |
| `None` | Stores nothing, for turning caching off. |
| `Redis` | phpredis, with reconnect and leases. |
| `Redis\Multiplexing` | Many Swoole coroutines over one Redis connection — see [the multiplexing guide](docs/multiplexing.md). |
| `RedisCluster` | phpredis against a Redis cluster. |
| `Memcached` | Memcached over the binary protocol. |
| `Hazelcast` | Hazelcast over its Memcached protocol. |
| `Sharding` | Spreads keys across several adapters. |
| `Pool` | Checks an adapter out of a `utopia-php/pools` pool per call. |
| `CircuitBreaker` | Wraps an adapter so a failing cache stops being called. |

## Codecs

The Redis, Redis\Multiplexing, RedisCluster and Hazelcast adapters store each value through a `Utopia\Cache\Codec`. The default is `Codec\Json`, which writes the same JSON payloads as every earlier release, so existing caches keep working. Pass `Codec\Igbinary` for smaller, faster binary payloads that need the `igbinary` extension:

```php
<?php

use Utopia\Cache\Adapter\Redis as RedisAdapter;
use Utopia\Cache\Cache;
use Utopia\Cache\Codec\Igbinary;

$cache = new Cache(new RedisAdapter($redis, new Igbinary()));
```

Adapters treat a payload they cannot decode as a miss, so switching the codec on a live cache re-populates it instead of failing. The Memcached adapter serializes through the extension's own `OPT_SERIALIZER` option, and Filesystem and Memory store values as given, so none of them take a codec.

## System requirements

The library requires PHP 8.4 or later. The Redis, Memcached and Hazelcast adapters need the matching extension — see the `suggest` block in `composer.json`.

## Tests

The unit tier needs nothing running:

```bash
composer test
```

The end-to-end tier runs on the host against this package's services:

```bash
docker compose up -d --wait
composer test:e2e
docker compose down -v
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)

## Benchmarks

The codec benchmark times each codec alone and through the Redis adapter. It needs the `igbinary` extension and starts Redis from the package's compose file when nothing is listening on the offset port:

```bash
composer bench
```
