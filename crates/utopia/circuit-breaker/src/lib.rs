//! A circuit breaker stops calling a dependency that keeps failing, so
//! callers get a fallback at once instead of waiting on errors, and the
//! dependency gets room to recover.
//!
//! A [`CircuitBreaker`] starts [`State::Closed`]: calls go through, and their
//! outcomes fill a rolling window. Once the window holds enough calls
//! (`minimum_throughput`) and the share that failed reaches `failure_ratio`,
//! the circuit opens: calls take their fallback without running. After
//! `timeout` seconds it is half open, and a limited number of probes go
//! through at once: `success_threshold` successes close it, one failure
//! opens it again.
//!
//! A call that failed with one of the `slow` error types answered too
//! slowly rather than failed: it never counts toward `failure_ratio`, only
//! as a call in its denominator. Instead the circuit opens once
//! `slow_ratio` of the window's calls were slow or failed (over
//! `slow_minimum_throughput` calls), or once a window of
//! `minimum_throughput` calls has no success at all. A slow probe is
//! neutral; `slow_minimum_throughput` slow probes in one half-open period
//! open the circuit again.
//!
//! The verdict (state, when it opened, probe counts) can be shared between
//! processes through an [`Adapter`] ([`Redis`], or a [`Table`] in one
//! process); the window is always measured per process. Breakers report
//! their calls, fallbacks and transitions to a [`Telemetry`] backend, and
//! their state as observable gauges.
//!
//! ```
//! use utopia_circuit_breaker::{CircuitBreaker, Options, State};
//!
//! # utopia_async::run(async {
//! let breaker = CircuitBreaker::new(Options { minimum_throughput: 2, ..Options::default() })?;
//!
//! for _ in 0..2 {
//!     let value = breaker.call(|| "fallback", async { Err::<&str, _>("failed") }).await?;
//!     assert_eq!(value, "fallback");
//! }
//!
//! assert_eq!(breaker.state().await?, State::Open);
//! # Ok::<(), utopia_circuit_breaker::Error>(())
//! # })?;
//! # Ok::<(), Box<dyn std::error::Error>>(())
//! ```
//!
//! ```php
//! use Utopia\CircuitBreaker\CircuitBreaker;
//!
//! $breaker = new CircuitBreaker(minimumThroughput: 2);
//!
//! for ($i = 0; $i < 2; $i++) {
//!     echo $breaker->call(
//!         open: fn () => 'fallback',
//!         close: fn () => throw new \RuntimeException('failed'),
//!     ), "\n"; // fallback
//! }
//!
//! echo $breaker->getState()->value, "\n"; // open
//! ```
//!
//! PHP: ports `utopia-php/circuit-breaker`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\CircuitBreaker\CircuitBreaker` | [`CircuitBreaker`], configured with [`Options`] |
//! | `call(open, close, halfOpen)` | [`CircuitBreaker::call`], [`CircuitBreaker::call_with_probe`]: `close` and `half_open` are futures returning `Result`; an `Err` is a failed call |
//! | `getState()`, `isOpen()`, `isClosed()`, `isHalfOpen()` | [`CircuitBreaker::state`] |
//! | `getFailureCount()`, `getSlowCount()`, `getSuccessCount()` | [`CircuitBreaker::failures`], [`CircuitBreaker::slow`], [`CircuitBreaker::successes`] |
//! | `trip()`, `setTelemetry()` | [`CircuitBreaker::trip`], [`CircuitBreaker::set_telemetry`] |
//! | `Utopia\CircuitBreaker\CircuitState` | [`State`] |
//! | `Utopia\CircuitBreaker\Adapter` | [`Adapter`] (values are [`Stored`]) |
//! | `Adapter\Redis` | [`Redis`] (feature `redis`) |
//! | `Adapter\SwooleTable`, `SwooleTable::createTable()` | [`Table`], [`SharedTable::new`] |
//! | `Adapter\AdapterException`, `\InvalidArgumentException` | [`Error::Adapter`], [`Error::InvalidArgument`] |
//! | the exception class of a failed call (`exception.type`) | [`Failure::exception_type`] |
//! | `slow` exceptions matched with `instanceof` | [`Options::slow`], matched with [`Failure::is_a`] |

#![warn(missing_docs)]

mod adapter;
mod error;

use std::borrow::Cow;
use std::future::Future;
use std::sync::{Arc, Mutex, MutexGuard, RwLock, Weak};

#[cfg(feature = "redis")]
pub use adapter::Redis;
pub use adapter::{Adapter, SharedTable, Stored, Table};
pub use error::Error;
use utopia_telemetry::{Counter, Gauge, Number, Observation, Telemetry, Value};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Where a circuit stands.
///
/// PHP: `Utopia\CircuitBreaker\CircuitState`.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum State {
    /// Calls go through.
    Closed,
    /// Calls take their fallback without running.
    Open,
    /// A limited number of probes go through to test recovery.
    HalfOpen,
}

impl State {
    /// The state's name: `closed`, `open` or `half_open`.
    ///
    /// PHP: `CircuitState::...->value`.
    pub fn as_str(self) -> &'static str {
        match self {
            State::Closed => "closed",
            State::Open => "open",
            State::HalfOpen => "half_open",
        }
    }

    /// The state named `name`, `None` for another name.
    ///
    /// PHP: `CircuitState::tryFrom()`.
    pub fn from_name(name: &str) -> Option<Self> {
        match name {
            "closed" => Some(State::Closed),
            "open" => Some(State::Open),
            "half_open" => Some(State::HalfOpen),
            _ => None,
        }
    }

    fn gauge(self) -> i64 {
        match self {
            State::Closed => 0,
            State::Open => 1,
            State::HalfOpen => 2,
        }
    }
}

/// A failed call's error: the breaker reports its type as the
/// `exception.type` attribute of its metrics. Implement it for the error
/// type the guarded calls return.
///
/// PHP: the class of the exception the callback threw (`$e::class`).
pub trait Failure {
    /// The name of the error's type, such as `RuntimeException`.
    fn exception_type(&self) -> Cow<'_, str>;

    /// Whether the error is of the type named `class` or one derived from
    /// it: how a failure is matched against [`Options::slow`]. By default
    /// only its own type, [`Failure::exception_type`]; override it for
    /// errors with a hierarchy.
    ///
    /// PHP: `$error instanceof $class`.
    fn is_a(&self, class: &str) -> bool {
        self.exception_type() == class
    }
}

impl Failure for &str {
    /// `Exception`: a message carries no type.
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed("Exception")
    }
}

impl Failure for String {
    /// `Exception`: a message carries no type.
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed("Exception")
    }
}

impl Failure for Error {
    /// The PHP exception class of the error.
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed(self.php_class())
    }
}

impl Failure for Box<dyn std::error::Error + Send + Sync> {
    /// `Exception`.
    fn exception_type(&self) -> Cow<'_, str> {
        Cow::Borrowed("Exception")
    }
}

/// How a [`CircuitBreaker`] judges and recovers.
///
/// PHP: the constructor's arguments, with the same defaults.
#[derive(Debug, Clone, PartialEq)]
pub struct Options {
    /// Seconds the circuit stays open before it is half open.
    pub timeout: i64,
    /// Successful probes that close a half-open circuit.
    pub success_threshold: i64,
    /// Names the circuit: the key of its shared state, and the
    /// `circuit_breaker.name` attribute of its metrics.
    pub key: String,
    /// Prepended (with a dot) to every metric name; dots around it are
    /// trimmed.
    pub metric_prefix: String,
    /// Seconds of history the failure rate is judged over.
    pub window: i64,
    /// The share of calls in the window that must fail to open the circuit,
    /// in (0, 1].
    pub failure_ratio: f64,
    /// Calls the window must hold before its ratio is trusted.
    pub minimum_throughput: i64,
    /// Probes allowed at once while half open; the rest take the fallback.
    pub half_open_permitted_calls: i64,
    /// Error types (matched with [`Failure::is_a`]) meaning the dependency
    /// answered too slowly rather than failed: such a call never counts
    /// toward `failure_ratio`, returns the fallback, and is judged by
    /// `slow_ratio` instead.
    pub slow: Vec<String>,
    /// The share of calls in the window that must have been slow or failed
    /// to open the circuit, in (0, 1]. Ignored when `slow` is empty.
    pub slow_ratio: f64,
    /// Calls the window must hold before `slow_ratio` is trusted, at least
    /// 1; `None` uses `minimum_throughput`. A window with no success opens
    /// at `minimum_throughput` regardless. While half open, this many slow
    /// probes in one period open the circuit again.
    pub slow_minimum_throughput: Option<i64>,
}

impl Default for Options {
    /// Open for 30 s, closed after 2 successful probes, judged over 10 s
    /// once 20 calls were made, opening at a 50% failure rate, one probe at a
    /// time, named `default`, without a metric prefix, and no slow error
    /// types (a slow ratio of 1, the slow minimum the failure minimum).
    fn default() -> Self {
        Options {
            timeout: 30,
            success_threshold: 2,
            key: "default".into(),
            metric_prefix: String::new(),
            window: 10,
            failure_ratio: 0.5,
            minimum_throughput: 20,
            half_open_permitted_calls: 1,
            slow: Vec::new(),
            slow_ratio: 1.0,
            slow_minimum_throughput: None,
        }
    }
}

/// What the breaker knows; never held across an `await`.
#[derive(Debug, Default)]
struct Tally {
    state: Option<State>,
    failures: i64,
    successes: i64,
    opened_at: Option<i64>,
    half_open_in_flight: i64,
    /// Slow probes this process saw in the current half-open period.
    half_open_slow: i64,
    active_calls: i64,
    slot: i64,
    window_failures: i64,
    window_slow: i64,
    window_calls: i64,
    previous_failures: i64,
    previous_slow: i64,
    previous_calls: i64,
}

impl Tally {
    fn state(&self) -> State {
        self.state.unwrap_or(State::Closed)
    }

    fn window_failure_count(&self) -> i64 {
        self.window_failures + self.previous_failures
    }

    fn window_slow_count(&self) -> i64 {
        self.window_slow + self.previous_slow
    }

    fn window_call_count(&self) -> i64 {
        self.window_calls + self.previous_calls
    }

    fn reset_window(&mut self, window: i64) {
        self.slot = now().div_euclid(window);
        self.window_failures = 0;
        self.window_slow = 0;
        self.window_calls = 0;
        self.previous_failures = 0;
        self.previous_slow = 0;
        self.previous_calls = 0;
    }

    /// Adds one outcome (a success when neither `failed` nor `slow`) to the
    /// window.
    fn record(&mut self, window: i64, failed: bool, slow: bool) {
        let slot = now().div_euclid(window);
        if slot != self.slot {
            let elapsed = slot - self.slot;
            self.previous_failures = if elapsed == 1 { self.window_failures } else { 0 };
            self.previous_slow = if elapsed == 1 { self.window_slow } else { 0 };
            self.previous_calls = if elapsed == 1 { self.window_calls } else { 0 };
            self.window_failures = 0;
            self.window_slow = 0;
            self.window_calls = 0;
            self.slot = slot;
        }
        self.window_failures += i64::from(failed);
        self.window_slow += i64::from(slow);
        self.window_calls += 1;
    }

    /// The window holds `minimum` calls and `count` of them reach `ratio`.
    fn exceeds_ratio(&self, count: i64, ratio: f64, minimum: i64) -> bool {
        let calls = self.window_call_count();
        calls >= minimum && (count as f64 / calls as f64) >= ratio
    }

    /// The slow rule: slow and failed calls together reach `slow_ratio`
    /// over the slow minimum, or the window holds the failure minimum of
    /// calls and none succeeded. Only with slow error types configured.
    fn exceeds_slow_rule(&self, options: &Options) -> bool {
        if options.slow.is_empty() {
            return false;
        }
        let unsuccessful = self.window_slow_count() + self.window_failure_count();
        self.exceeds_ratio(unsuccessful, options.slow_ratio, options.slow_minimum())
            || (self.window_call_count() >= options.minimum_throughput && unsuccessful == self.window_call_count())
    }
}

impl Options {
    fn slow_minimum(&self) -> i64 {
        self.slow_minimum_throughput.unwrap_or(self.minimum_throughput)
    }
}

struct Instruments {
    calls: Counter,
    callback_failures: Counter,
    fallbacks: Counter,
    transitions: Counter,
    event_timestamp: Gauge,
}

struct Shared {
    options: Options,
    cache: Option<Arc<dyn Adapter>>,
    tally: Mutex<Tally>,
    instruments: RwLock<Option<Arc<Instruments>>>,
    telemetry: Mutex<Option<Arc<dyn Telemetry>>>,
}

fn now() -> i64 {
    php_std::datetime::time()
}

fn lock<T>(mutex: &Mutex<T>) -> MutexGuard<'_, T> {
    mutex.lock().unwrap_or_else(|e| e.into_inner())
}

/// The breakers observed through each telemetry backend and metric prefix:
/// one set of observable gauges per pair reports every live breaker in it.
struct Observed {
    telemetry: Weak<dyn Telemetry>,
    prefix: String,
    breakers: Arc<Mutex<Vec<Weak<Shared>>>>,
}

static OBSERVED: Mutex<Vec<Observed>> = Mutex::new(Vec::new());

fn same_backend(a: &Weak<dyn Telemetry>, b: &Arc<dyn Telemetry>) -> bool {
    std::ptr::addr_eq(a.as_ptr(), Arc::as_ptr(b))
}

/// A circuit breaker. See the [crate docs](crate) for how it decides.
///
/// ```
/// use utopia_circuit_breaker::{CircuitBreaker, Options, State};
///
/// let breaker = CircuitBreaker::new(Options { timeout: 60, ..Options::default() })?;
///
/// utopia_async::run(async {
///     breaker.trip().await?;
///     assert_eq!(breaker.state().await?, State::Open);
///     assert_eq!(breaker.call(|| "fallback", async { Ok::<_, &str>("live") }).await?, "fallback");
///     Ok::<_, utopia_circuit_breaker::Error>(())
/// })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\CircuitBreaker\CircuitBreaker;
///
/// $breaker = new CircuitBreaker(timeout: 60);
/// $breaker->trip();
/// var_dump($breaker->isOpen()); // bool(true)
/// echo $breaker->call(open: fn () => 'fallback', close: fn () => 'live'), "\n"; // fallback
/// ```
///
/// PHP: `Utopia\CircuitBreaker\CircuitBreaker`.
pub struct CircuitBreaker {
    shared: Arc<Shared>,
}

impl std::fmt::Debug for CircuitBreaker {
    /// Writes the options.
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CircuitBreaker").field("options", &self.shared.options).finish()
    }
}

impl CircuitBreaker {
    /// A closed breaker keeping its state in this process.
    ///
    /// Fails with [`Error::InvalidArgument`] when an option is out of range.
    ///
    /// PHP: `new CircuitBreaker(...)` without `cache`.
    pub fn new(options: Options) -> Result<Self, Error> {
        Self::build(options, None)
    }

    /// A breaker sharing its verdict through `cache` under `options.key`.
    ///
    /// Fails with [`Error::InvalidArgument`] when an option is out of range
    /// or the key is empty.
    ///
    /// PHP: `new CircuitBreaker(cache: $cache, key: ...)`.
    pub fn with_cache(options: Options, cache: Arc<dyn Adapter>) -> Result<Self, Error> {
        if options.key.is_empty() {
            return Err(Error::InvalidArgument("Key must not be empty when a cache adapter is configured.".into()));
        }
        Self::build(options, Some(cache))
    }

    fn build(options: Options, cache: Option<Arc<dyn Adapter>>) -> Result<Self, Error> {
        let invalid = |m: &str| Err(Error::InvalidArgument(m.to_owned()));
        if options.window <= 0 {
            return invalid("Window must be greater than 0 seconds.");
        }
        if options.failure_ratio <= 0.0 || options.failure_ratio > 1.0 || options.failure_ratio.is_nan() {
            return invalid("Failure ratio must be greater than 0 and at most 1.");
        }
        if options.minimum_throughput < 1 {
            return invalid("Minimum throughput must be at least 1.");
        }
        if options.half_open_permitted_calls < 1 {
            return invalid("Half-open permitted calls must be at least 1.");
        }
        if options.slow.iter().any(String::is_empty) {
            return invalid("Slow exceptions must be Throwable class names.");
        }
        if options.slow_ratio <= 0.0 || options.slow_ratio > 1.0 || options.slow_ratio.is_nan() {
            return invalid("Slow ratio must be greater than 0 and at most 1.");
        }
        if options.slow_minimum_throughput.is_some_and(|m| m < 1) {
            return invalid("Slow minimum throughput must be at least 1.");
        }
        Ok(CircuitBreaker {
            shared: Arc::new(Shared {
                options,
                cache,
                tally: Mutex::new(Tally::default()),
                instruments: RwLock::new(None),
                telemetry: Mutex::new(None),
            }),
        })
    }

    /// The options the breaker was made with.
    pub fn options(&self) -> &Options {
        &self.shared.options
    }

    /// Records the breaker's metrics with `telemetry` from now on: counters
    /// `breaker.calls`, `breaker.callback_failures`, `breaker.fallbacks` and
    /// `breaker.transitions`, the gauge `breaker.event.timestamp`, and the
    /// observable gauges `breaker.active_calls`, `breaker.state`,
    /// `breaker.failures`, `breaker.successes` and `breaker.slow` (read
    /// when the backend collects), all prefixed with the metric prefix.
    ///
    /// A breaker observed by one backend and prefix is reported once, by
    /// one set of gauges shared with every other breaker bound there; binding
    /// to another backend moves it, and a dropped breaker stops reporting.
    ///
    /// PHP: `setTelemetry()`, or the constructor's `telemetry` argument.
    pub fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        let shared = &self.shared;
        let calls = telemetry.counter(&shared.metric_name("breaker.calls"), Some("{call}"), None);
        {
            let mut observed = lock(&OBSERVED);
            observed.retain(|o| o.telemetry.strong_count() > 0);
            let previous = lock(&shared.telemetry).clone();
            if let Some(previous) = previous.filter(|p| !Arc::ptr_eq(p, &telemetry)) {
                for o in observed.iter().filter(|o| same_backend(&o.telemetry, &previous)) {
                    if o.prefix == shared.options.metric_prefix {
                        lock(&o.breakers).retain(|b| !std::ptr::eq(b.as_ptr(), Arc::as_ptr(shared)));
                    }
                }
            }
            let breakers = match observed
                .iter()
                .find(|o| same_backend(&o.telemetry, &telemetry) && o.prefix == shared.options.metric_prefix)
            {
                Some(o) => o.breakers.clone(),
                None => {
                    let breakers = Arc::new(Mutex::new(Vec::new()));
                    observe(&*telemetry, &shared.options.metric_prefix, &breakers);
                    observed.push(Observed {
                        telemetry: Arc::downgrade(&telemetry),
                        prefix: shared.options.metric_prefix.clone(),
                        breakers: breakers.clone(),
                    });
                    breakers
                }
            };
            let mut list = lock(&breakers);
            list.retain(|b| b.strong_count() > 0);
            if !list.iter().any(|b| std::ptr::eq(b.as_ptr(), Arc::as_ptr(shared))) {
                list.push(Arc::downgrade(shared));
            }
        }
        let instruments = Instruments {
            calls,
            callback_failures: telemetry.counter(
                &shared.metric_name("breaker.callback_failures"),
                Some("{failure}"),
                None,
            ),
            fallbacks: telemetry.counter(&shared.metric_name("breaker.fallbacks"), Some("{fallback}"), None),
            transitions: telemetry.counter(&shared.metric_name("breaker.transitions"), Some("{transition}"), None),
            event_timestamp: telemetry.gauge(&shared.metric_name("breaker.event.timestamp"), Some("s"), None),
        };
        *lock(&shared.telemetry) = Some(telemetry);
        *shared.instruments.write().unwrap_or_else(|e| e.into_inner()) = Some(Arc::new(instruments));
    }

    /// Runs a call through the breaker.
    ///
    /// While closed, `close` runs; while half open, `half_open` runs as the
    /// probe (or `close` when there is none) if fewer than
    /// `half_open_permitted_calls` probes are running. When the call
    /// returns `Ok`, its value is returned; when it returns `Err`, the
    /// failure counts against the dependency (or, for one of the `slow`
    /// error types, the call counts as slow) and `open`'s fallback is
    /// returned. While open, or when the probes are saturated, `open`'s
    /// fallback is returned without running anything.
    ///
    /// Fails only when the shared store fails.
    ///
    /// PHP: `call(callable $open, callable $close)`, where a callback that
    /// throws is a failed call.
    pub async fn call<T, E: Failure>(
        &self,
        open: impl FnOnce() -> T,
        close: impl Future<Output = Result<T, E>>,
    ) -> Result<T, Error> {
        self.run(open, close, None::<std::future::Pending<Result<T, E>>>).await
    }

    /// Runs a call through the breaker, with `half_open` as the probe while
    /// the circuit is half open; otherwise the same as
    /// [`CircuitBreaker::call`].
    ///
    /// PHP: `call(callable $open, callable $close, callable $halfOpen)`.
    pub async fn call_with_probe<T, E: Failure>(
        &self,
        open: impl FnOnce() -> T,
        close: impl Future<Output = Result<T, E>>,
        half_open: impl Future<Output = Result<T, E>>,
    ) -> Result<T, Error> {
        self.run(open, close, Some(half_open)).await
    }

    async fn run<T, E: Failure>(
        &self,
        open: impl FnOnce() -> T,
        close: impl Future<Output = Result<T, E>>,
        half_open: Option<impl Future<Output = Result<T, E>>>,
    ) -> Result<T, Error> {
        let shared = &self.shared;
        let instruments = shared.instruments.read().unwrap_or_else(|e| e.into_inner()).clone();
        let mut initial = lock(&shared.tally).state();
        let mut outcome = "unknown";
        let mut exception: Option<String> = None;
        let mut entered = false;
        let mut probing = false;

        let result: Result<T, Error> = async {
            shared.refresh(instruments.as_deref()).await?;
            {
                let mut tally = lock(&shared.tally);
                initial = tally.state();
                tally.active_calls += 1;
                entered = true;
                if initial == State::Open {
                    outcome = "short_circuit";
                } else if initial == State::HalfOpen {
                    if tally.half_open_in_flight >= shared.options.half_open_permitted_calls {
                        outcome = "short_circuit";
                    } else {
                        probing = true;
                        tally.half_open_in_flight += 1;
                    }
                }
            }
            if outcome == "short_circuit" {
                let reason = if initial == State::Open { "open" } else { "half_open_saturated" };
                if let Some(i) = &instruments {
                    i.fallbacks.add(
                        1,
                        &shared.attributes(&[
                            ("circuit_breaker.reason", Value::from(reason)),
                            ("circuit_breaker.state", Value::from(initial.as_str())),
                        ]),
                    );
                }
                return Ok(open());
            }
            let called = match half_open {
                Some(probe) if initial == State::HalfOpen => probe.await,
                _ => close.await,
            };
            let slow = |e: &dyn Failure| shared.options.slow.iter().any(|class| e.is_a(class));
            let (failure, is_slow) = match called {
                Ok(value) => match shared.on_success(instruments.as_deref()).await {
                    Ok(()) => {
                        outcome = "success";
                        return Ok(value);
                    }
                    Err(e) => (e.exception_type().into_owned(), slow(&e)),
                },
                Err(e) => (e.exception_type().into_owned(), slow(&e)),
            };
            if is_slow {
                exception = Some(failure);
                shared.on_slow(instruments.as_deref()).await?;
                let state = lock(&shared.tally).state();
                if let Some(i) = &instruments {
                    i.fallbacks.add(
                        1,
                        &shared.attributes(&[
                            ("circuit_breaker.reason", Value::from("slow")),
                            ("circuit_breaker.state", Value::from(state.as_str())),
                        ]),
                    );
                }
                outcome = "slow";
                return Ok(open());
            }
            let state = lock(&shared.tally).state();
            if let Some(i) = &instruments {
                i.callback_failures.add(
                    1,
                    &shared.attributes(&[
                        ("exception.type", Value::from(failure.as_str())),
                        ("circuit_breaker.state", Value::from(state.as_str())),
                    ]),
                );
            }
            exception = Some(failure);
            shared.on_failure(instruments.as_deref()).await?;
            let state = lock(&shared.tally).state();
            if let Some(i) = &instruments {
                i.fallbacks.add(
                    1,
                    &shared.attributes(&[
                        ("circuit_breaker.reason", Value::from("failure")),
                        ("circuit_breaker.state", Value::from(state.as_str())),
                    ]),
                );
            }
            outcome = "fallback";
            Ok(open())
        }
        .await;

        if let Err(e) = &result {
            if exception.is_none() {
                exception = Some(e.php_class().to_owned());
            }
            outcome = match outcome {
                "unknown" => "exception",
                "fallback" => "fallback_exception",
                "success" => "success_exception",
                "slow" => "slow_exception",
                _ => "short_circuit_exception",
            };
        }
        let state = {
            let mut tally = lock(&shared.tally);
            if probing {
                tally.half_open_in_flight -= 1;
            }
            tally.state()
        };
        if let Some(i) = &instruments {
            let mut attributes = vec![
                ("circuit_breaker.name", Value::from(shared.options.key.as_str())),
                ("circuit_breaker.initial_state", Value::from(initial.as_str())),
                ("circuit_breaker.state", Value::from(state.as_str())),
                ("circuit_breaker.outcome", Value::from(outcome)),
            ];
            if let Some(e) = &exception {
                attributes.push(("exception.type", Value::from(e.as_str())));
            }
            i.calls.add(1, &attributes);
            if initial == State::HalfOpen {
                shared.record_event(
                    Some(i),
                    "probe",
                    &format!("probe: {outcome}"),
                    &[("circuit_breaker.outcome", Value::from(outcome))],
                );
            }
        }
        if entered {
            lock(&shared.tally).active_calls -= 1;
        }
        result
    }

    /// Where the circuit stands now: an open circuit whose timeout has
    /// passed becomes half open here.
    ///
    /// PHP: `getState()` (and `isOpen()`, `isClosed()`, `isHalfOpen()`).
    pub async fn state(&self) -> Result<State, Error> {
        let instruments = self.shared.instruments.read().unwrap_or_else(|e| e.into_inner()).clone();
        self.shared.refresh(instruments.as_deref()).await?;
        Ok(lock(&self.shared.tally).state())
    }

    /// The failures the circuit is judging on: the window's while closed or
    /// open, the probes' while half open.
    ///
    /// PHP: `getFailureCount()`.
    pub async fn failures(&self) -> Result<i64, Error> {
        self.shared.sync_from_cache().await?;
        let tally = lock(&self.shared.tally);
        Ok(if tally.state() == State::HalfOpen { tally.failures } else { tally.window_failure_count() })
    }

    /// Slow calls in the window.
    ///
    /// PHP: `getSlowCount()`.
    pub async fn slow(&self) -> Result<i64, Error> {
        self.shared.sync_from_cache().await?;
        Ok(lock(&self.shared.tally).window_slow_count())
    }

    /// Successful probes since the circuit was last half open.
    ///
    /// PHP: `getSuccessCount()`.
    pub async fn successes(&self) -> Result<i64, Error> {
        self.shared.sync_from_cache().await?;
        Ok(lock(&self.shared.tally).successes)
    }

    /// Opens the circuit now. Tripping an open circuit restarts its timeout
    /// without recording another transition.
    ///
    /// PHP: `trip()`.
    pub async fn trip(&self) -> Result<(), Error> {
        let instruments = self.shared.instruments.read().unwrap_or_else(|e| e.into_inner()).clone();
        self.shared.sync_from_cache().await?;
        self.shared.transition_to_open(instruments.as_deref()).await
    }
}

/// Registers the five observable gauges reporting every breaker in
/// `breakers` through `telemetry`.
fn observe(telemetry: &dyn Telemetry, prefix: &str, breakers: &Arc<Mutex<Vec<Weak<Shared>>>>) {
    let prefix = prefix.trim_matches('.');
    let name = |n: &str| if prefix.is_empty() { n.to_owned() } else { format!("{prefix}.{n}") };
    type Read = fn(&Tally) -> i64;
    let gauges: [(&str, Option<&str>, bool, Read); 5] = [
        ("breaker.active_calls", Some("{call}"), false, |t| t.active_calls),
        ("breaker.state", None, true, |t| t.state().gauge()),
        ("breaker.failures", Some("{failure}"), true, |t| {
            if t.state() == State::HalfOpen { t.failures } else { t.window_failure_count() }
        }),
        ("breaker.successes", Some("{success}"), true, |t| t.successes),
        ("breaker.slow", Some("{call}"), false, |t| t.window_slow_count()),
    ];
    for (gauge, unit, sync, read) in gauges {
        let breakers = breakers.clone();
        telemetry.observable_gauge(&name(gauge), unit, None).observe_async(move || {
            let live: Vec<Arc<Shared>> = lock(&breakers).iter().filter_map(Weak::upgrade).collect();
            async move {
                let mut out = Vec::with_capacity(live.len());
                for breaker in live {
                    if sync {
                        let _ = breaker.sync_from_cache().await;
                    }
                    let value = read(&lock(&breaker.tally));
                    out.push(Observation {
                        value: Number::Int(value),
                        attributes: vec![("circuit_breaker.name".to_owned(), Value::from(breaker.options.key.clone()))],
                    });
                }
                out
            }
        });
    }
}

const STATE: &str = "state";
const FAILURES: &str = "failures";
const SUCCESSES: &str = "successes";
const OPENED_AT: &str = "opened_at";

impl Shared {
    fn metric_name(&self, name: &str) -> String {
        let prefix = self.options.metric_prefix.trim_matches('.');
        if prefix.is_empty() { name.to_owned() } else { format!("{prefix}.{name}") }
    }

    fn attributes<'a>(&'a self, extra: &[(&'a str, Value<'a>)]) -> Vec<(&'a str, Value<'a>)> {
        let mut attributes = Vec::with_capacity(extra.len() + 1);
        attributes.push(("circuit_breaker.name", Value::from(self.options.key.as_str())));
        attributes.extend(extra.iter().cloned());
        attributes
    }

    fn field(&self, field: &str) -> String {
        format!("{}:{field}", self.options.key)
    }

    async fn sync_from_cache(&self) -> Result<(), Error> {
        let Some(cache) = &self.cache else { return Ok(()) };
        let state = match cache.get(&self.field(STATE)).await? {
            Some(Stored::Str(s)) => State::from_name(&s).unwrap_or(State::Closed),
            _ => State::Closed,
        };
        let failures = integer(cache.get(&self.field(FAILURES)).await?).map_or(0, |n| n.max(0));
        let successes = integer(cache.get(&self.field(SUCCESSES)).await?).map_or(0, |n| n.max(0));
        let opened_at = integer(cache.get(&self.field(OPENED_AT)).await?);
        let mut tally = lock(&self.tally);
        // A slow-probe count belongs to one half-open period: another
        // process ending it or starting the next one clears it.
        if state != State::HalfOpen || opened_at != tally.opened_at {
            tally.half_open_slow = 0;
        }
        tally.state = Some(state);
        tally.failures = failures;
        tally.successes = successes;
        tally.opened_at = opened_at;
        Ok(())
    }

    /// [`Shared::update_state`], without awaiting anything when there is
    /// nothing to read or move: the state is local, and only an open circuit
    /// past its timeout changes. The rest is boxed, so the futures of the
    /// common path stay small.
    async fn refresh(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        if self.cache.is_none() {
            let tally = lock(&self.tally);
            if tally.state() != State::Open || tally.opened_at.is_none_or(|at| now() - at < self.options.timeout) {
                return Ok(());
            }
        }
        Box::pin(self.update_state(instruments)).await
    }

    async fn update_state(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        self.sync_from_cache().await?;
        let timed_out = {
            let tally = lock(&self.tally);
            tally.state() == State::Open && tally.opened_at.is_some_and(|at| now() - at >= self.options.timeout)
        };
        if timed_out {
            let from = lock(&self.tally).state();
            self.set_failures(0).await?;
            self.set_successes(0).await?;
            {
                let mut tally = lock(&self.tally);
                tally.reset_window(self.options.window);
                tally.half_open_slow = 0;
            }
            self.set_state(State::HalfOpen).await?;
            self.record_transition(instruments, from, State::HalfOpen);
        }
        Ok(())
    }

    async fn on_success(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        let state = lock(&self.tally).state();
        match state {
            State::HalfOpen => {
                let successes = self.increment_successes().await?;
                if successes >= self.options.success_threshold {
                    self.transition_to_closed(instruments).await?;
                }
            }
            State::Closed => {
                lock(&self.tally).record(self.options.window, false, false);
            }
            State::Open => {}
        }
        Ok(())
    }

    async fn on_failure(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        if lock(&self.tally).state() == State::HalfOpen {
            self.increment_failures().await?;
            return self.transition_to_open(instruments).await;
        }
        let opens = {
            let mut tally = lock(&self.tally);
            tally.record(self.options.window, true, false);
            let o = &self.options;
            tally.exceeds_ratio(tally.window_failure_count(), o.failure_ratio, o.minimum_throughput)
                || tally.exceeds_slow_rule(o)
        };
        if opens {
            self.transition_to_open(instruments).await?;
        }
        Ok(())
    }

    /// A slow call only dilutes the failure ratio; the slow rule counts
    /// every call that did not succeed. A slow probe is neutral until the
    /// slow minimum of them in one half-open period opens the circuit.
    async fn on_slow(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        let opens = {
            let mut tally = lock(&self.tally);
            match tally.state() {
                State::HalfOpen => {
                    tally.half_open_slow += 1;
                    tally.half_open_slow >= self.options.slow_minimum()
                }
                State::Open => false,
                State::Closed => {
                    tally.record(self.options.window, false, true);
                    tally.exceeds_slow_rule(&self.options)
                }
            }
        };
        if opens {
            self.transition_to_open(instruments).await?;
        }
        Ok(())
    }

    async fn transition_to_open(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        let from = lock(&self.tally).state();
        self.set_opened_at(Some(now())).await?;
        self.set_successes(0).await?;
        lock(&self.tally).half_open_slow = 0;
        self.set_state(State::Open).await?;
        self.record_transition(instruments, from, State::Open);
        Ok(())
    }

    async fn transition_to_closed(&self, instruments: Option<&Instruments>) -> Result<(), Error> {
        let from = lock(&self.tally).state();
        self.set_failures(0).await?;
        self.set_successes(0).await?;
        {
            let mut tally = lock(&self.tally);
            tally.reset_window(self.options.window);
            tally.half_open_slow = 0;
        }
        self.set_opened_at(None).await?;
        self.set_state(State::Closed).await?;
        self.record_transition(instruments, from, State::Closed);
        Ok(())
    }

    async fn set_state(&self, state: State) -> Result<(), Error> {
        lock(&self.tally).state = Some(state);
        match &self.cache {
            Some(cache) => cache.set(&self.field(STATE), Stored::from(state.as_str())).await,
            None => Ok(()),
        }
    }

    async fn set_failures(&self, failures: i64) -> Result<(), Error> {
        lock(&self.tally).failures = failures;
        match &self.cache {
            Some(cache) => cache.set(&self.field(FAILURES), Stored::Int(failures)).await,
            None => Ok(()),
        }
    }

    async fn set_successes(&self, successes: i64) -> Result<(), Error> {
        lock(&self.tally).successes = successes;
        match &self.cache {
            Some(cache) => cache.set(&self.field(SUCCESSES), Stored::Int(successes)).await,
            None => Ok(()),
        }
    }

    async fn increment_failures(&self) -> Result<i64, Error> {
        let Some(cache) = &self.cache else {
            let mut tally = lock(&self.tally);
            tally.failures += 1;
            return Ok(tally.failures);
        };
        let failures = cache.increment(&self.field(FAILURES), 1).await?;
        lock(&self.tally).failures = failures;
        Ok(failures)
    }

    async fn increment_successes(&self) -> Result<i64, Error> {
        let Some(cache) = &self.cache else {
            let mut tally = lock(&self.tally);
            tally.successes += 1;
            return Ok(tally.successes);
        };
        let successes = cache.increment(&self.field(SUCCESSES), 1).await?;
        lock(&self.tally).successes = successes;
        Ok(successes)
    }

    async fn set_opened_at(&self, opened_at: Option<i64>) -> Result<(), Error> {
        lock(&self.tally).opened_at = opened_at;
        let Some(cache) = &self.cache else { return Ok(()) };
        match opened_at {
            None => cache.delete(&self.field(OPENED_AT)).await,
            Some(at) => cache.set(&self.field(OPENED_AT), Stored::Int(at)).await,
        }
    }

    fn record_transition(&self, instruments: Option<&Instruments>, from: State, to: State) {
        if from == to {
            return;
        }
        let Some(i) = instruments else { return };
        i.transitions.add(
            1,
            &self.attributes(&[
                ("circuit_breaker.from_state", Value::from(from.as_str())),
                ("circuit_breaker.to_state", Value::from(to.as_str())),
            ]),
        );
        self.record_event(
            Some(i),
            "transition",
            &format!("{} -> {}", from.as_str(), to.as_str()),
            &[
                ("circuit_breaker.from_state", Value::from(from.as_str())),
                ("circuit_breaker.to_state", Value::from(to.as_str())),
            ],
        );
    }

    fn record_event(&self, instruments: Option<&Instruments>, kind: &str, name: &str, extra: &[(&str, Value<'_>)]) {
        let Some(i) = instruments else { return };
        let mut attributes = vec![
            ("circuit_breaker.name", Value::from(self.options.key.as_str())),
            ("circuit_breaker.event", Value::from(kind)),
            ("circuit_breaker.event_name", Value::from(name)),
        ];
        attributes.extend(extra.iter().cloned());
        i.event_timestamp.record(php_std::datetime::microtime_float(), &attributes);
    }
}

/// `is_numeric($value) ? (int) $value : null`.
fn integer(value: Option<Stored>) -> Option<i64> {
    match value? {
        Stored::Int(i) => Some(i),
        Stored::Str(s) => {
            php_std::value::numeric_bytes_ex(s.as_bytes())?;
            Some(php_std::format::Arg::Str(s.as_bytes()).to_int())
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    async fn fail() -> Result<&'static str, &'static str> {
        Err("failed")
    }

    #[utopia_async::test]
    async fn opens_on_the_failure_rate() {
        let breaker = CircuitBreaker::new(Options { minimum_throughput: 2, ..Options::default() }).unwrap();
        for _ in 0..2 {
            assert_eq!(breaker.call(|| "fallback", fail()).await.unwrap(), "fallback");
        }
        assert_eq!(breaker.state().await.unwrap(), State::Open);
        assert_eq!(breaker.failures().await.unwrap(), 2);
    }

    #[utopia_async::test]
    async fn shares_the_verdict_through_a_table() {
        let table = SharedTable::default();
        let options = Options { key: "users-api".into(), minimum_throughput: 2, ..Options::default() };
        let first =
            CircuitBreaker::with_cache(options.clone(), Arc::new(Table::new(table.clone(), "breaker:"))).unwrap();
        let second = CircuitBreaker::with_cache(options, Arc::new(Table::new(table, "breaker:"))).unwrap();
        for _ in 0..2 {
            first.call(|| "fallback", fail()).await.unwrap();
        }
        assert_eq!(second.state().await.unwrap(), State::Open);
        assert_eq!(second.failures().await.unwrap(), 0);
    }

    struct Timeout;

    impl Failure for Timeout {
        fn exception_type(&self) -> Cow<'_, str> {
            Cow::Borrowed("Timeout")
        }
    }

    fn slow_options(minimum_throughput: i64, slow_minimum_throughput: Option<i64>) -> Options {
        Options {
            minimum_throughput,
            slow: vec!["Timeout".into()],
            slow_ratio: 0.9,
            slow_minimum_throughput,
            window: 60,
            ..Options::default()
        }
    }

    #[utopia_async::test]
    async fn slow_calls_are_not_failures() {
        let breaker = CircuitBreaker::new(slow_options(3, Some(20))).unwrap();
        breaker.call(|| "fallback", async { Ok::<_, Timeout>("ok") }).await.unwrap();
        for _ in 0..5 {
            let value = breaker.call(|| "fallback", async { Err::<&str, _>(Timeout) }).await.unwrap();
            assert_eq!(value, "fallback");
        }
        assert_eq!(breaker.state().await.unwrap(), State::Closed);
        assert_eq!(breaker.failures().await.unwrap(), 0);
        assert_eq!(breaker.slow().await.unwrap(), 5);
    }

    #[utopia_async::test]
    async fn a_window_without_success_opens_on_the_failure_minimum() {
        let breaker = CircuitBreaker::new(slow_options(3, Some(20))).unwrap();
        for _ in 0..2 {
            breaker.call(|| "fallback", async { Err::<&str, _>(Timeout) }).await.unwrap();
        }
        assert_eq!(breaker.state().await.unwrap(), State::Closed);
        breaker.call(|| "fallback", fail()).await.unwrap();
        assert_eq!(breaker.state().await.unwrap(), State::Open);
        assert_eq!((breaker.failures().await.unwrap(), breaker.slow().await.unwrap()), (1, 2));
    }

    #[test]
    fn rejects_out_of_range_slow_options() {
        let error = CircuitBreaker::new(Options { slow_ratio: 0.0, ..Options::default() }).unwrap_err();
        assert_eq!(error.to_string(), "Slow ratio must be greater than 0 and at most 1.");
        let error =
            CircuitBreaker::new(Options { slow_minimum_throughput: Some(0), ..Options::default() }).unwrap_err();
        assert_eq!(error.to_string(), "Slow minimum throughput must be at least 1.");
    }

    #[test]
    fn rejects_out_of_range_options() {
        let error = CircuitBreaker::new(Options { window: 0, ..Options::default() }).unwrap_err();
        assert_eq!(error.to_string(), "Window must be greater than 0 seconds.");
    }
}
