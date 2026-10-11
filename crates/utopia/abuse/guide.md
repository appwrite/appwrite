A rate limit caps how often a client may do something: sign in, send a message, call an endpoint. This library counts requests per key (an IP, a user, an endpoint, or all three) and says, for each one, whether it is over the limit, how many requests are left and when the count resets. It has three ways of counting: fixed windows (`TimeLimit`), sliding windows (`SlidingWindow`) and token buckets (`TokenBucket`), each over a store: Redis, a Redis Cluster, a pool of connections, a document store, or nothing at all. Stores are async, so the examples run them on a Tokio runtime. This guide covers the common tasks.

## Limit requests per window

A `TimeLimit` allows `limit` requests in each window of `seconds`, windows aligned on the Unix epoch so every server counts in the same ones. `check` counts a request and says whether it is over the limit; once the window is full, every request is limited until the next one. `check_at` takes the time instead of reading the clock.

```
use utopia_abuse::{TimeLimit, time_limit};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let store = time_limit::Database::new(time_limit::Memory::new());
    store.setup().await?;
    let limit = TimeLimit::new("login:{email}", 2, 60, store)?;

    assert!(!limit.check_at(1_700_000_000).await?.limited);
    assert!(!limit.check_at(1_700_000_001).await?.limited);
    assert!(limit.check_at(1_700_000_002).await?.limited);

    // The next minute is a new window.
    assert!(!limit.check_at(1_700_000_040).await?.limited);
    Ok::<_, utopia_abuse::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\TimeLimit\Database as Store;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Database;

$db = new Database(new Memory(), new Cache(new NoCache()));
$db->setDatabase('abuse');
$db->create();

$limit = new Store('login:{email}', 2, 60, $db);
$limit->setup();

var_dump($limit->check()->limited); // bool(false)
var_dump($limit->check()->limited); // bool(false)
var_dump($limit->check()->limited); // bool(true)
```

## Build the key from the request

A key is a pattern with placeholders. `with_params` (or `with_param` for one) returns a copy whose key has them filled in, counting in the same store, so build the limit once and derive one per request. The longest placeholder that matches wins, and a value is never scanned for placeholders again.

```
use utopia_abuse::{Adapter, TimeLimit, time_limit};

let limit = TimeLimit::new("url:{url},ip:{ip}", 10, 60, time_limit::None)?;
let request = limit.with_params([("{url}", "/v1/account/sessions"), ("{ip}", "203.0.113.7")]);

assert_eq!(request.key(), "url:/v1/account/sessions,ip:203.0.113.7");
assert_eq!(limit.key(), "url:{url},ip:{ip}");
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\TimeLimit\None;

$limit = new None('url:{url},ip:{ip}', 10, 60);
$request = $limit->withParams(['{url}' => '/v1/account/sessions', '{ip}' => '203.0.113.7']);

echo $request->key(), "\n"; // url:/v1/account/sessions,ip:203.0.113.7
echo $limit->key(), "\n"; // url:{url},ip:{ip}
```

## Answer with rate-limit headers

Every check answers a `Verdict`: whether the request is limited, the limit, the requests left after this one and when the window resets, ready for the `X-RateLimit-*` headers.

```
use utopia_abuse::{TimeLimit, time_limit};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let limit = TimeLimit::new("ip:{ip}", 60, 3600, time_limit::None)?;
    let verdict = limit.check_at(1_700_000_000).await?;

    let headers = [
        ("X-RateLimit-Limit", verdict.limit.to_string()),
        ("X-RateLimit-Remaining", verdict.remaining.to_string()),
        ("X-RateLimit-Reset", verdict.reset.to_string()),
    ];
    assert_eq!(headers[2].1, "1700002800");
    Ok::<_, utopia_abuse::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\TimeLimit\None;

$verdict = new None('ip:{ip}', 60, 3600)->check();

$headers = [
    'X-RateLimit-Limit' => $verdict->limit,
    'X-RateLimit-Remaining' => $verdict->remaining,
    'X-RateLimit-Reset' => $verdict->reset,
];
echo $headers['X-RateLimit-Limit'], "\n"; // 60
```

## Count in Redis

The Redis stores keep one counter per key and window, `abuse__<key>__<window start>`, expiring with the window, and count with a script so concurrent servers never overshoot. PHP and Rust write the same keys and values, so both runtimes count against the same limits.

```no_run
use utopia_abuse::{Adapter, TimeLimit, time_limit};

tokio::runtime::Builder::new_current_thread().enable_all().build()?.block_on(async {
    let conn = redis::Client::open("redis://localhost:6379")?.get_connection_manager().await?;
    let limit = TimeLimit::new("guide:{ip}", 2, 60, time_limit::Redis::new(conn))?.with_param("{ip}", "203.0.113.7");

    limit.reset().await?;
    assert!(!limit.check().await?.limited);
    assert!(!limit.check().await?.limited);
    assert!(limit.check().await?.limited);
    Ok::<_, Box<dyn std::error::Error>>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\TimeLimit\Redis;

$redis = new \Redis();
$redis->connect('redis', 6379);
$limit = new Redis('guide:{ip}', 2, 60, $redis)->withParam('{ip}', '203.0.113.7');

$limit->reset();
var_dump($limit->check()->limited); // bool(false)
var_dump($limit->check()->limited); // bool(false)
var_dump($limit->check()->limited); // bool(true)
```

## Smooth the window edges

A fixed window lets a client spend its whole limit at the end of one window and again at the start of the next. A `SlidingWindow` also counts the previous window, weighted by how much of it the last `window` seconds still cover.

```
use utopia_abuse::{SlidingWindow, sliding_window};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let limit = SlidingWindow::new("ip:{ip}", 100, 60, sliding_window::None)?;
    let verdict = limit.check_at(1_700_000_000).await?;

    assert_eq!((verdict.limited, verdict.remaining, verdict.reset), (false, 99, 1_700_000_040));
    Ok::<_, utopia_abuse::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\SlidingWindow\None;

$verdict = new None('ip:{ip}', 100, 60)->check();
echo $verdict->remaining, "\n"; // 99
```

## Allow bursts with a token bucket

A `TokenBucket` holds up to `tokens` tokens and refills at `refill_rate` a second. Each request takes one, so a client can burst up to the capacity and then go on at the refill rate. The reset time is when the bucket will be full again.

```
use utopia_abuse::{TokenBucket, token_bucket};

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let limit = TokenBucket::new("ip:{ip}", 10, 0.5, token_bucket::None)?;
    let verdict = limit.check_at(1_700_000_000.0).await?;

    assert_eq!((verdict.limited, verdict.remaining, verdict.reset), (false, 9, 1_700_000_002));
    Ok::<_, utopia_abuse::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Abuse\Adapter\TokenBucket\None;

$verdict = new None('ip:{ip}', 10, 0.5)->check();
echo $verdict->remaining, "\n"; // 9
```

## Tell people from bots

`ReCaptcha` sends a reCAPTCHA v3 token to Google and passes it when Google answers with a score of at least the threshold. Give it your own `Client` to route or replay its traffic:

```
use utopia_abuse::ReCaptcha;
use utopia_abuse::recaptcha::{BoxFuture, Client, Request};

struct Google;

impl Client for Google {
    fn send<'a>(&'a self, _request: Request) -> BoxFuture<'a, utopia_abuse::Result<Vec<u8>>> {
        Box::pin(async { Ok(br#"{"success": true, "score": 0.7}"#.to_vec()) })
    }
}

tokio::runtime::Builder::new_current_thread().build()?.block_on(async {
    let recaptcha = ReCaptcha::with_client("site-secret", Google);
    assert!(recaptcha.verify("token", "203.0.113.7", 0.5).await?);
    assert!(!recaptcha.verify("token", "203.0.113.7", 0.9).await?);
    Ok::<_, utopia_abuse::Error>(())
})?;
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Abuse\ReCaptcha;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

$google = new class () implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, body: new Stream('{"success": true, "score": 0.7}'));
    }
};

$recaptcha = new ReCaptcha('site-secret', $google);
var_dump($recaptcha->verify('token', '203.0.113.7')); // bool(true)
var_dump($recaptcha->verify('token', '203.0.113.7', 0.9)); // bool(false)
```
