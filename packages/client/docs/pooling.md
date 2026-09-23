# Pooling

`Utopia\Client\Pool` borrows a client from a [utopia-php/pools](https://github.com/utopia-php/pools)
pool for the duration of each request and reclaims it when the request completes,
so concurrent callers share a bounded set of connections instead of each opening
their own.

```bash
composer require utopia-php/pools
```

```php
<?php

use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Pool;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as Connections;

$pool = new Pool(new Connections(
    adapter: new Stack(),
    name: 'example',
    size: 10,
    init: fn (): Client => new Client((new CurlAdapter())->withConnectionReuse()),
));

$response = $pool->sendRequest($request);
$pool->stream($request, $sink);
```

Pair the pooled adapters with [`withConnectionReuse()`](configuration.md#connection-reuse):
without it the pool still bounds concurrency, but each borrow dials a fresh
connection, so the handshake savings are lost.

Because `Utopia\Client` implements `Adapter` (and therefore both
`Psr\Http\Client\ClientInterface` and `Utopia\Psr18\StreamingClientInterface`),
the `init` callback can return a fully configured client — base URI, default
headers, auth, retries — so every pooled connection carries the same setup:

```php
<?php

init: fn (): Client => (new Client(new Retry((new CurlAdapter())->withConnectionReuse())))
    ->withBaseUri('https://api.example.com')
    ->withBearerAuth($token),
```

## Swoole

Use the `Utopia\Pools\Adapter\Swoole` pool adapter in a coroutine runtime so each
coroutine borrows a distinct connection. Run the pool inside `Coroutine\run()`,
and have `init` return a Swoole adapter with reuse enabled.

```php
<?php

use Utopia\Client;
use Utopia\Client\Adapter\SwooleCoroutine\Client as SwooleAdapter;
use Utopia\Client\Pool;
use Utopia\Pools\Adapter\Swoole;
use Utopia\Pools\Pool as Connections;

$pool = new Pool(new Connections(
    adapter: new Swoole(),
    name: 'example',
    size: 10,
    init: fn (): Client => new Client((new SwooleAdapter())->withConnectionReuse()),
));
```

## Notes

- `Pool` implements `ClientInterface` and `StreamingClientInterface`, not `Adapter`
  — it has no `with*()` helpers, since pooling load-balances across many
  connections. Configure the connections in `init` instead.
- Connections are created lazily and on demand: a `size: 10` pool under sequential
  load reuses a single connection; it only opens more when concurrent callers hold
  connections at the same time.
- Reuse is per origin. A pool whose requests span many hosts re-dials on each host
  change, so pooling pays off most against a single upstream.
- A stale pooled connection (dropped by the server while idle) is handled by the
  transport: the cURL handle and the Swoole client both detect a dead socket and
  reconnect on the next request.
