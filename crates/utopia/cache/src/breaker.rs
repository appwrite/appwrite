//! [`CircuitBreaker`]: an adapter whose failures fall back instead of
//! failing, behind a [`Breaker`] that stops calling a failing backend.

use std::future::Future;
use std::sync::{Arc, Mutex, OnceLock};

use php_std::types::Value;

use crate::Result;
use crate::adapter::{Adapter, Fields, now};
use crate::telemetry::{Counter, Telemetry};

/// Decides whether calls reach a dependency, from how recent calls went.
/// Implement it to share a breaker's state across processes (the role of
/// `Utopia\CircuitBreaker\CircuitBreaker`).
pub trait Breaker: Send + Sync {
    /// What an admitted call carries until it completes.
    type Ticket: Send;

    /// Whether a call may reach the dependency now: `Some` lets it through,
    /// `None` means the caller takes its fallback (the circuit is open, or
    /// enough probes are already running).
    fn admit(&self) -> Option<Self::Ticket>;

    /// Reports how an admitted call went; a failure counts against the
    /// dependency.
    fn complete(&self, ticket: Self::Ticket, succeeded: bool);

    /// Records the breaker's metrics with `telemetry`.
    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        let _ = telemetry;
    }
}

/// Where a [`Circuit`] stands.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum State {
    /// Calls go through.
    Closed,
    /// Calls fall back without running.
    Open,
    /// A limited number of probes go through to test recovery.
    HalfOpen,
}

impl State {
    fn as_str(self) -> &'static str {
        match self {
            State::Closed => "closed",
            State::Open => "open",
            State::HalfOpen => "half-open",
        }
    }
}

/// Settings of a [`Circuit`], with the defaults of `utopia-php/circuit-breaker`.
#[derive(Debug, Clone, PartialEq)]
pub struct CircuitOptions {
    /// Seconds the circuit stays open before probing.
    pub timeout: i64,
    /// Successful probes that close the circuit.
    pub success_threshold: i64,
    /// Seconds of history the failure rate is judged over.
    pub window: i64,
    /// Share of calls in the window that must fail to open the circuit.
    pub failure_ratio: f64,
    /// Calls needed in the window before the ratio is trusted.
    pub minimum_throughput: i64,
    /// Probes allowed at once while half open.
    pub half_open_permitted_calls: i64,
    /// Name in metric attributes (`circuit_breaker.name`).
    pub key: String,
}

impl Default for CircuitOptions {
    fn default() -> Self {
        CircuitOptions {
            timeout: 30,
            success_threshold: 2,
            window: 10,
            failure_ratio: 0.5,
            minimum_throughput: 20,
            half_open_permitted_calls: 1,
            key: "default".into(),
        }
    }
}

#[derive(Debug, Default)]
struct Tally {
    state: Option<State>,
    successes: i64,
    opened_at: Option<i64>,
    half_open_in_flight: i64,
    slot: i64,
    failures: i64,
    calls: i64,
    previous_failures: i64,
    previous_calls: i64,
}

/// An in-process circuit breaker: closed until the share of failed calls in
/// a rolling window reaches the ratio (once enough calls were seen), then
/// open for `timeout` seconds, then half open, where probes decide whether it
/// closes again or reopens.
pub struct Circuit {
    options: CircuitOptions,
    tally: Mutex<Tally>,
    calls: OnceLock<Arc<dyn Counter>>,
}

impl Circuit {
    /// A closed circuit. Fails when an option is out of range.
    pub fn new(options: CircuitOptions) -> Result<Self> {
        let invalid = |m: &str| Err(crate::Error::InvalidArgument(m.to_owned()));
        if options.window <= 0 {
            return invalid("Window must be greater than 0 seconds.");
        }
        if options.failure_ratio <= 0.0 || options.failure_ratio > 1.0 {
            return invalid("Failure ratio must be greater than 0 and at most 1.");
        }
        if options.minimum_throughput < 1 {
            return invalid("Minimum throughput must be at least 1.");
        }
        if options.half_open_permitted_calls < 1 {
            return invalid("Half-open permitted calls must be at least 1.");
        }
        Ok(Circuit { options, tally: Mutex::new(Tally::default()), calls: OnceLock::new() })
    }

    /// Where the circuit stands now (an open circuit past its timeout is half open).
    pub fn state(&self) -> State {
        let mut tally = self.lock();
        self.update(&mut tally);
        tally.state.unwrap_or(State::Closed)
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, Tally> {
        self.tally.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn update(&self, tally: &mut Tally) {
        if tally.state == Some(State::Open) && tally.opened_at.is_some_and(|at| now() - at >= self.options.timeout) {
            tally.successes = 0;
            self.reset_window(tally);
            tally.state = Some(State::HalfOpen);
        }
    }

    fn reset_window(&self, tally: &mut Tally) {
        tally.slot = now().div_euclid(self.options.window);
        tally.failures = 0;
        tally.calls = 0;
        tally.previous_failures = 0;
        tally.previous_calls = 0;
    }

    fn open(&self, tally: &mut Tally) {
        tally.opened_at = Some(now());
        tally.successes = 0;
        tally.state = Some(State::Open);
    }

    fn record(&self, tally: &mut Tally, failed: bool) -> (i64, i64) {
        let slot = now().div_euclid(self.options.window);
        if slot != tally.slot {
            let elapsed = slot - tally.slot;
            tally.previous_failures = if elapsed == 1 { tally.failures } else { 0 };
            tally.previous_calls = if elapsed == 1 { tally.calls } else { 0 };
            tally.failures = 0;
            tally.calls = 0;
            tally.slot = slot;
        }
        tally.failures += i64::from(failed);
        tally.calls += 1;
        (tally.failures + tally.previous_failures, tally.calls + tally.previous_calls)
    }

    fn on_success(&self, tally: &mut Tally) {
        match tally.state.unwrap_or(State::Closed) {
            State::HalfOpen => {
                tally.successes += 1;
                if tally.successes >= self.options.success_threshold {
                    tally.successes = 0;
                    self.reset_window(tally);
                    tally.opened_at = None;
                    tally.state = Some(State::Closed);
                }
            }
            State::Closed => {
                self.record(tally, false);
            }
            State::Open => {}
        }
    }

    fn on_failure(&self, tally: &mut Tally) {
        if tally.state == Some(State::HalfOpen) {
            self.open(tally);
            return;
        }
        let (failures, calls) = self.record(tally, true);
        if calls >= self.options.minimum_throughput && (failures as f64 / calls as f64) >= self.options.failure_ratio {
            self.open(tally);
        }
    }
}

/// A call [`Circuit`] let through: the state it started in, and whether
/// it is a half-open probe.
#[derive(Debug, Clone, Copy)]
pub struct Admitted {
    initial: State,
    probing: bool,
}

impl Breaker for Circuit {
    type Ticket = Admitted;

    fn admit(&self) -> Option<Admitted> {
        let mut tally = self.lock();
        self.update(&mut tally);
        let state = tally.state.unwrap_or(State::Closed);
        let admitted = match state {
            State::Open => None,
            State::HalfOpen if tally.half_open_in_flight >= self.options.half_open_permitted_calls => None,
            State::HalfOpen => {
                tally.half_open_in_flight += 1;
                Some(Admitted { initial: state, probing: true })
            }
            State::Closed => Some(Admitted { initial: state, probing: false }),
        };
        drop(tally);
        if admitted.is_none() {
            self.count(state, state, "short_circuit");
        }
        admitted
    }

    fn complete(&self, ticket: Admitted, succeeded: bool) {
        let state = {
            let mut tally = self.lock();
            if ticket.probing {
                tally.half_open_in_flight -= 1;
            }
            if succeeded {
                self.on_success(&mut tally);
            } else {
                self.on_failure(&mut tally);
            }
            tally.state.unwrap_or(State::Closed)
        };
        self.count(ticket.initial, state, if succeeded { "success" } else { "fallback" });
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        let _ = self.calls.set(telemetry.counter("breaker.calls", Some("{call}"), None));
    }
}

impl Circuit {
    fn count(&self, initial: State, state: State, outcome: &str) {
        if let Some(calls) = self.calls.get() {
            calls.add(
                1.0,
                &[
                    ("circuit_breaker.name", &self.options.key),
                    ("circuit_breaker.initial_state", initial.as_str()),
                    ("circuit_breaker.state", state.as_str()),
                    ("circuit_breaker.outcome", outcome),
                ],
            );
        }
    }
}

impl<B: Breaker> Breaker for Arc<B> {
    type Ticket = B::Ticket;

    fn admit(&self) -> Option<B::Ticket> {
        (**self).admit()
    }

    fn complete(&self, ticket: B::Ticket, succeeded: bool) {
        (**self).complete(ticket, succeeded)
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        (**self).set_telemetry(telemetry)
    }
}

/// An adapter behind a [`Breaker`]: while the backend fails, operations
/// return a fallback (a miss, `false`, an empty list, `0`) instead of an
/// error, and an open circuit skips the backend entirely.
///
/// ```
/// use php_std::types::Value;
/// use utopia_cache::{Adapter, Circuit, CircuitBreaker, CircuitOptions, Memory};
///
/// # tokio::runtime::Runtime::new()?.block_on(async {
/// let cache = CircuitBreaker::new(Memory::new(), Circuit::new(CircuitOptions::default())?);
/// assert!(cache.save("key", &Value::from("value"), "", 0).await?);
/// assert_eq!(cache.load("key", 60, "").await?, Some(Value::from("value")));
/// # Ok::<(), utopia_cache::Error>(())
/// # })?;
/// # Ok::<(), Box<dyn std::error::Error>>(())
/// ```
///
/// ```php
/// use Utopia\Cache\Adapter\CircuitBreaker;
/// use Utopia\Cache\Adapter\Memory;
/// use Utopia\CircuitBreaker\CircuitBreaker as Breaker;
///
/// $cache = new CircuitBreaker(new Memory(), new Breaker());
/// $cache->save('key', 'value');
/// echo $cache->load('key', 60), "\n"; // value
/// ```
pub struct CircuitBreaker<A, B> {
    adapter: A,
    breaker: B,
}

impl<A: Adapter, B: Breaker> CircuitBreaker<A, B> {
    /// `adapter` behind `breaker`.
    pub fn new(adapter: A, breaker: B) -> Self {
        CircuitBreaker { adapter, breaker }
    }

    /// The adapter behind the breaker.
    pub fn adapter(&self) -> &A {
        &self.adapter
    }

    /// The breaker.
    pub fn breaker(&self) -> &B {
        &self.breaker
    }

    /// `call`'s value when the breaker lets it through and it succeeds;
    /// `fallback` otherwise.
    async fn guarded<T>(&self, fallback: T, call: impl Future<Output = Result<T>>) -> T {
        let Some(ticket) = self.breaker.admit() else { return fallback };
        match call.await {
            Ok(value) => {
                self.breaker.complete(ticket, true);
                value
            }
            Err(_) => {
                self.breaker.complete(ticket, false);
                fallback
            }
        }
    }
}

impl<A: Adapter, B: Breaker> Adapter for CircuitBreaker<A, B> {
    async fn load(&self, key: &str, ttl: i64, hash: &str) -> Result<Option<Value>> {
        Ok(self.guarded(None, self.adapter.load(key, ttl, hash)).await)
    }

    async fn save(&self, key: &str, data: &Value, hash: &str, ttl: i64) -> Result<bool> {
        Ok(self.guarded(false, self.adapter.save(key, data, hash, ttl)).await)
    }

    async fn touch(&self, key: &str, hash: &str) -> Result<bool> {
        Ok(self.guarded(false, self.adapter.touch(key, hash)).await)
    }

    async fn list(&self, key: &str) -> Result<Vec<String>> {
        Ok(self.guarded(Vec::new(), self.adapter.list(key)).await)
    }

    async fn purge(&self, key: &str, hash: &str) -> Result<bool> {
        Ok(self.guarded(false, self.adapter.purge(key, hash)).await)
    }

    async fn flush(&self) -> Result<bool> {
        Ok(self.guarded(false, self.adapter.flush()).await)
    }

    async fn ping(&self) -> Result<bool> {
        Ok(self.guarded(false, self.adapter.ping()).await)
    }

    async fn size(&self) -> Result<i64> {
        Ok(self.guarded(0, self.adapter.size()).await)
    }

    fn name(&self, key: Option<&str>) -> &str {
        self.adapter.name(key)
    }

    fn batchable(&self) -> bool {
        true
    }

    async fn load_many(&self, key: &str, fields: &[&str], ttl: i64) -> Result<Fields> {
        if !self.adapter.batchable() {
            return Ok(Fields::new());
        }
        Ok(self.guarded(Fields::new(), self.adapter.load_many(key, fields, ttl)).await)
    }

    async fn save_many(&self, key: &str, data: &Fields, ttl: i64) -> Result<bool> {
        if !self.adapter.batchable() {
            return Ok(false);
        }
        Ok(self.guarded(false, self.adapter.save_many(key, data, ttl)).await)
    }

    fn leasable(&self) -> bool {
        true
    }

    async fn generation(&self, key: &str) -> Result<String> {
        if !self.adapter.leasable() {
            return Ok("0".to_owned());
        }
        Ok(self.guarded("0".to_owned(), self.adapter.generation(key)).await)
    }

    async fn save_with_lease(&self, key: &str, data: &Value, hash: &str, generation: &str) -> Result<bool> {
        if !self.adapter.leasable() {
            return self.save(key, data, hash, 0).await;
        }
        Ok(self.guarded(false, self.adapter.save_with_lease(key, data, hash, generation)).await)
    }

    fn set_telemetry(&self, telemetry: Arc<dyn Telemetry>) {
        self.breaker.set_telemetry(telemetry.clone());
        self.adapter.set_telemetry(telemetry);
    }
}
