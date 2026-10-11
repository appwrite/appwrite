//! Retrying transient failures: the [`Retry`] decorator and its
//! [`Strategy`].

use std::future::Future;
use std::sync::Arc;
use std::time::Duration;

use crate::adapter::{Adapter, Options, Sink};
use crate::{Error, Request, Response};

/// What happened on one attempt: the response, or the error.
#[derive(Debug, Clone, Copy)]
pub enum Outcome<'a> {
    /// The adapter returned a response (4xx and 5xx included).
    Response(&'a Response),
    /// The adapter failed.
    Error(&'a Error),
}

/// Decides whether to retry after an attempt. Implement it for a policy of
/// your own.
///
/// PHP: `Utopia\Client\Decorator\Retry\Strategy`.
pub trait Strategy: Send + Sync {
    /// The seconds to wait before the next attempt, or `None` to stop and
    /// return the outcome. `attempt` counts from 1.
    ///
    /// PHP: `delay($request, $attempt, $response, $error)`, where exactly one
    /// of `$response` and `$error` is set.
    fn delay(&self, request: &Request, attempt: u32, outcome: Outcome<'_>) -> Option<f64>;
}

/// The default, best-practice strategy: retry only idempotent methods
/// (`GET`, `HEAD`, `PUT`, `DELETE`, `OPTIONS`, `TRACE`), only on network
/// failures ([`Error::is_network`]) and overloaded responses (429, 502, 503,
/// 504), with exponential backoff and full jitter. A numeric `Retry-After`
/// is honoured, capped to the maximum delay.
///
/// PHP: `Utopia\Client\Decorator\Retry\Backoff`.
///
/// ```
/// use utopia_client::{Backoff, Error, Outcome, Request, Strategy};
///
/// // A randomizer that always returns 1.0 shows the ceiling of each delay.
/// let backoff = Backoff::new(4, 0.1, 10.0, 2.0).with_randomizer(|| 1.0);
/// let request = Request::new("GET", "https://example.com/")?;
/// let error = Error::Connection("reset".into());
/// assert_eq!(backoff.delay(&request, 1, Outcome::Error(&error)), Some(0.1));
/// assert_eq!(backoff.delay(&request, 2, Outcome::Error(&error)), Some(0.2));
/// assert_eq!(backoff.delay(&request, 4, Outcome::Error(&error)), None);
/// # Ok::<(), utopia_client::Error>(())
/// ```
///
/// ```php
/// use Utopia\Client\Decorator\Retry\Backoff;
/// use Utopia\Client\Exception\ConnectionException;
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Uri;
///
/// $backoff = new Backoff(4, 0.1, 10.0, 2.0, fn (): float => 1.0);
/// $request = new Request('GET', Uri::parse('https://example.com/'));
/// $error = new ConnectionException($request, 'reset');
/// var_dump($backoff->delay($request, 1, null, $error)); // float(0.1)
/// var_dump($backoff->delay($request, 2, null, $error)); // float(0.2)
/// var_dump($backoff->delay($request, 4, null, $error)); // NULL
/// ```
#[derive(Clone)]
pub struct Backoff {
    max_attempts: i64,
    base_delay: f64,
    max_delay: f64,
    multiplier: f64,
    randomizer: Arc<dyn Fn() -> f64 + Send + Sync>,
}

impl std::fmt::Debug for Backoff {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Backoff")
            .field("max_attempts", &self.max_attempts)
            .field("base_delay", &self.base_delay)
            .field("max_delay", &self.max_delay)
            .field("multiplier", &self.multiplier)
            .finish()
    }
}

const IDEMPOTENT: [&str; 6] = ["GET", "HEAD", "PUT", "DELETE", "OPTIONS", "TRACE"];
const RETRYABLE: [u16; 4] = [429, 502, 503, 504];

impl Default for Backoff {
    /// 3 attempts, 0.1 s base delay, 10 s maximum, doubling.
    fn default() -> Backoff {
        Backoff { max_attempts: 3, base_delay: 0.1, max_delay: 10.0, multiplier: 2.0, randomizer: Arc::new(random) }
    }
}

impl Backoff {
    /// A strategy making at most `max_attempts` attempts, waiting up to
    /// `base_delay * multiplier^(attempt - 1)` seconds (never more than
    /// `max_delay`) between them.
    ///
    /// PHP: `new Backoff($maxAttempts, $baseDelay, $maxDelay, $multiplier)`.
    pub fn new(max_attempts: i64, base_delay: f64, max_delay: f64, multiplier: f64) -> Backoff {
        Backoff { max_attempts, base_delay, max_delay, multiplier, ..Backoff::default() }
    }

    /// With the source of jitter: a value in `[0, 1)` each call. Defaults to
    /// a uniform random number.
    ///
    /// PHP: the constructor's `$randomizer`.
    pub fn with_randomizer(mut self, randomizer: impl Fn() -> f64 + Send + Sync + 'static) -> Backoff {
        self.randomizer = Arc::new(randomizer);
        self
    }

    fn retry_after(&self, response: &Response) -> Option<f64> {
        let value = response.headers().line("Retry-After");
        let number = php_std::value::numeric_str(&value)?;
        let seconds = match number {
            php_std::value::Number::Int(i) => i as f64,
            php_std::value::Number::Float(f) => f,
        };
        Some(php_std::format::min_pair(self.max_delay, php_std::format::max_pair(0.0, seconds)))
    }
}

impl Strategy for Backoff {
    fn delay(&self, request: &Request, attempt: u32, outcome: Outcome<'_>) -> Option<f64> {
        if i64::from(attempt) >= self.max_attempts {
            return None;
        }
        if !IDEMPOTENT.contains(&request.method()) {
            return None;
        }
        let retryable = match outcome {
            Outcome::Error(error) => error.is_network(),
            Outcome::Response(response) => RETRYABLE.contains(&response.status()),
        };
        if !retryable {
            return None;
        }
        if let Outcome::Response(response) = outcome
            && let Some(seconds) = self.retry_after(response)
        {
            return Some(seconds);
        }
        let ceiling = php_std::format::min_pair(
            self.max_delay,
            self.base_delay * php_std::format::pow(self.multiplier, f64::from(attempt) - 1.0),
        );
        Some((self.randomizer)() * ceiling)
    }
}

/// A uniform random number in `[0, 1)`.
///
/// PHP: `mt_rand() / mt_getrandmax()`.
fn random() -> f64 {
    let mut bytes = [0u8; 8];
    if getrandom::fill(&mut bytes).is_err() {
        return 0.5;
    }
    (u64::from_le_bytes(bytes) >> 11) as f64 / (1u64 << 53) as f64
}

/// Waits between attempts. The default sleeps on tokio's timer; give your
/// own to record or skip waits.
///
/// PHP: the `Closure(float): void $sleep` of `Retry`.
pub trait Sleep: Send + Sync {
    /// Waits this many seconds.
    fn sleep(&self, seconds: f64) -> impl Future<Output = ()> + Send;
}

/// Sleeps on tokio's timer.
#[derive(Debug, Clone, Copy, Default)]
pub struct Timer;

impl Sleep for Timer {
    async fn sleep(&self, seconds: f64) {
        tokio::time::sleep(Duration::from_secs_f64(seconds.max(0.0))).await;
    }
}

/// An adapter that retries another according to a [`Strategy`]. The
/// strategy owns every decision (which outcomes to retry, how long to wait,
/// when to stop); the decorator only loops and sleeps. A stream is retried
/// only while no byte has reached its sink: replaying would duplicate them.
///
/// PHP: `Utopia\Client\Decorator\Retry`.
///
/// ```no_run
/// use utopia_client::{Backoff, Client, Http, Request, Retry};
///
/// # async fn run() -> Result<(), utopia_client::Error> {
/// let client = Client::new(Retry::new(Http::new(), Backoff::default()));
/// let response = client.send(Request::new("GET", "https://example.com/")?).await?;
/// # Ok(())
/// # }
/// ```
///
/// ```php
/// use Utopia\Client\Adapter\Curl\Client as Curl;
/// use Utopia\Client\Client;
/// use Utopia\Client\Decorator\Retry;
/// use Utopia\Client\Decorator\Retry\Backoff;
/// use Utopia\Psr7\Request;
/// use Utopia\Psr7\Uri;
///
/// $client = new Client(new Retry(new Curl(), new Backoff()));
/// $request = new Request('GET', Uri::parse('https://example.com/'));
/// // $client->sendRequest($request) retries a 503 or a dropped connection
/// ```
#[derive(Debug, Clone)]
pub struct Retry<A, S = Backoff, W = Timer> {
    adapter: A,
    strategy: S,
    sleep: W,
}

impl<A: Adapter, S: Strategy> Retry<A, S, Timer> {
    /// Retries `adapter` with `strategy`, sleeping on tokio's timer.
    ///
    /// PHP: `new Retry($adapter, $strategy)`.
    pub fn new(adapter: A, strategy: S) -> Retry<A, S, Timer> {
        Retry { adapter, strategy, sleep: Timer }
    }
}

impl<A: Adapter, S: Strategy, W: Sleep> Retry<A, S, W> {
    /// With another way to wait between attempts.
    ///
    /// PHP: the constructor's `$sleep`.
    pub fn with_sleep<V: Sleep>(self, sleep: V) -> Retry<A, S, V> {
        Retry { adapter: self.adapter, strategy: self.strategy, sleep }
    }

    /// The decorated adapter.
    pub fn inner(&self) -> &A {
        &self.adapter
    }
}

impl<A: Adapter, S: Strategy + Clone, W: Sleep + Clone> Adapter for Retry<A, S, W> {
    async fn send(&self, request: Request) -> Result<Response, Error> {
        let mut attempt = 1;
        loop {
            let delay = match self.adapter.send(request.clone()).await {
                Ok(response) => match self.strategy.delay(&request, attempt, Outcome::Response(&response)) {
                    None => return Ok(response),
                    Some(delay) => delay,
                },
                Err(error) if error.is_client() => match self.strategy.delay(&request, attempt, Outcome::Error(&error))
                {
                    None => return Err(error),
                    Some(delay) => delay,
                },
                Err(error) => return Err(error),
            };
            self.sleep.sleep(delay).await;
            attempt += 1;
        }
    }

    async fn stream<K: Sink>(&self, request: Request, sink: &mut K) -> Result<Response, Error> {
        let mut attempt = 1;
        loop {
            let mut delivered = 0usize;
            let mut counting = |chunk: &[u8]| {
                delivered += chunk.len();
                sink.write(chunk);
            };
            let result = self.adapter.stream(request.clone(), &mut counting).await;
            let delay = match result {
                Ok(response) => {
                    if delivered > 0 {
                        return Ok(response);
                    }
                    match self.strategy.delay(&request, attempt, Outcome::Response(&response)) {
                        None => return Ok(response),
                        Some(delay) => delay,
                    }
                }
                Err(error) if error.is_client() && delivered == 0 => {
                    match self.strategy.delay(&request, attempt, Outcome::Error(&error)) {
                        None => return Err(error),
                        Some(delay) => delay,
                    }
                }
                Err(error) => return Err(error),
            };
            self.sleep.sleep(delay).await;
            attempt += 1;
        }
    }

    fn options(&self) -> &Options {
        self.adapter.options()
    }

    fn with_options(&self, options: Options) -> Self {
        Retry {
            adapter: self.adapter.with_options(options),
            strategy: self.strategy.clone(),
            sleep: self.sleep.clone(),
        }
    }
}
