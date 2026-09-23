# Retries

`Utopia\Client\Decorator\Retry` is an adapter that decorates another adapter and
retries failed requests. Because it is itself an `Adapter`, it composes — wrap it in
`Client` (and stack it with other decorators) wherever a transport goes.

```php
<?php

use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Client\Decorator\Retry;

$client = new Client(new Retry(new CurlAdapter()));
```

With no configuration it uses `Backoff`, the default best-practice strategy:

- Retries only **idempotent** methods (`GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE`), so a lost response can't cause a double-submit.
- Retries only **transient** failures: transport errors (`NetworkExceptionInterface` — DNS, timeout, connection reset, TLS) and overloaded responses (`429`, `502`, `503`, `504`).
- Waits with **exponential backoff and full jitter**, honouring a numeric `Retry-After` header when present.
- Makes up to **3 attempts** total.

`RequestExceptionInterface` failures (a bad URI, a malformed response) are never
retried — see [error handling](error-handling.md) for that distinction. Streaming
requests are retried only when the failure occurs before any byte reaches the sink,
since replaying would duplicate delivered data.

## Tuning the default

`Backoff` is configurable through its constructor:

```php
<?php

use Utopia\Client\Decorator\Retry;
use Utopia\Client\Decorator\Retry\Backoff;

$client = new Client(
    new Retry(
        new CurlAdapter(),
        new Backoff(
            maxAttempts: 5,
            baseDelay: 0.2,   // seconds; first retry waits up to baseDelay
            maxDelay: 30.0,   // ceiling for any single wait
            multiplier: 2.0,  // delay ceiling grows by this factor each attempt
        ),
    ),
);
```

## Custom strategies

Every retry decision lives behind one interface, so a custom policy is a single
method. Return the number of seconds to wait, or `null` to stop and surface the
outcome:

```php
<?php

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Decorator\Retry;
use Utopia\Client\Decorator\Retry\Strategy;

final class RetryOnceImmediately implements Strategy
{
    public function delay(RequestInterface $request, int $attempt, ?ResponseInterface $response, ?ClientExceptionInterface $error): ?float
    {
        return $attempt === 1 && $error !== null ? 0.0 : null;
    }
}

$client = new Client(new Retry(new CurlAdapter(), new RetryOnceImmediately()));
```

Exactly one of `$response` or `$error` is non-null: `$response` when the inner
adapter returned (including `4xx`/`5xx`), `$error` when it threw.

## Swoole

The retry decorator sleeps between attempts with `usleep()` by default. With Swoole's
runtime coroutine hooks enabled (`Swoole\Runtime::enableCoroutine()`, which most
Swoole servers turn on), `usleep()` is hooked to yield to the scheduler instead of
blocking, so no special configuration is needed.

If you run without those hooks, inject a coroutine-aware sleeper:

```php
<?php

use Swoole\Coroutine;
use Utopia\Client\Decorator\Retry;

$retry = new Retry(
    $swooleAdapter,
    sleep: static fn (float $seconds) => Coroutine::sleep($seconds),
);
```

## Writing your own decorator

`Retry` extends `Utopia\Client\Decorator`, the base for any adapter that wraps
another. It forwards every configuration helper to the inner adapter and delegates
sending; a subclass overrides only `sendRequest()` / `stream()`. Because each
decorator is itself an `Adapter`, they stack in any order — for example
`new Client(new Retry(new SomeOtherDecorator(new CurlAdapter())))`.
