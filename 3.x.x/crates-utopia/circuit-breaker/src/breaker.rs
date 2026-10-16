use std::collections::HashMap;
use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

use parking_lot::Mutex;
use utopia_telemetry::{attrs, Adapter as TelemetryAdapter, Counter, Gauge, UpDownCounter};

use crate::adapter::{Adapter, CacheValue};
use crate::error::CircuitBreakerError;
use crate::state::CircuitState;

const STATE_FIELD: &str = "state";
const FAILURES_FIELD: &str = "failures";
const SUCCESSES_FIELD: &str = "successes";
const OPENED_AT_FIELD: &str = "opened_at";

struct Instruments {
    calls: Arc<dyn Counter>,
    active_calls: Arc<dyn UpDownCounter>,
    state_gauge: Arc<dyn Gauge>,
    failures_gauge: Arc<dyn Gauge>,
    successes_gauge: Arc<dyn Gauge>,
    callback_failures: Option<Arc<dyn Counter>>,
    fallbacks: Option<Arc<dyn Counter>>,
    transitions: Option<Arc<dyn Counter>>,
    event_timestamp: Option<Arc<dyn Gauge>>,
}

struct Inner {
    state: CircuitState,
    failures: i32,
    successes: i32,
    opened_at: Option<i64>,
}

/// PHP `Utopia\CircuitBreaker\CircuitBreaker`.
pub struct CircuitBreaker {
    threshold: i32,
    timeout: i64,
    success_threshold: i32,
    cache: Option<Arc<dyn Adapter>>,
    key: String,
    metric_prefix: String,
    inner: Mutex<Inner>,
    telemetry: Mutex<Option<Arc<dyn TelemetryAdapter>>>,
    instruments: Mutex<Option<Instruments>>,
}

impl std::fmt::Debug for CircuitBreaker {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CircuitBreaker")
            .field("threshold", &self.threshold)
            .field("timeout", &self.timeout)
            .field("key", &self.key)
            .finish_non_exhaustive()
    }
}

impl Default for CircuitBreaker {
    fn default() -> Self {
        Self::new()
    }
}

impl CircuitBreaker {
    /// PHP defaults: `threshold = 3`, `timeout = 30`, `successThreshold = 2`.
    #[must_use]
    pub fn new() -> Self {
        Self::with_config(3, 30, 2)
    }

    #[must_use]
    pub fn with_threshold(threshold: i32) -> Self {
        Self::with_config(threshold, 30, 2)
    }

    #[must_use]
    pub fn with_config(threshold: i32, timeout: i64, success_threshold: i32) -> Self {
        Self {
            threshold,
            timeout,
            success_threshold,
            cache: None,
            key: "default".into(),
            metric_prefix: String::new(),
            inner: Mutex::new(Inner {
                state: CircuitState::Closed,
                failures: 0,
                successes: 0,
                opened_at: None,
            }),
            telemetry: Mutex::new(None),
            instruments: Mutex::new(None),
        }
    }

    pub fn with_adapter(
        threshold: i32,
        timeout: i64,
        success_threshold: i32,
        cache: Option<Arc<dyn Adapter>>,
        key: impl Into<String>,
        telemetry: Option<Arc<dyn TelemetryAdapter>>,
        metric_prefix: impl Into<String>,
    ) -> Result<Self, CircuitBreakerError> {
        let key = key.into();
        if cache.is_some() && key.is_empty() {
            return Err(CircuitBreakerError::empty_key());
        }
        let mut breaker = Self::with_config(threshold, timeout, success_threshold);
        breaker.cache = cache;
        breaker.key = key;
        breaker.metric_prefix = metric_prefix.into();
        if let Some(telemetry) = telemetry {
            breaker.set_telemetry(telemetry);
        }
        breaker.sync_from_cache();
        Ok(breaker)
    }

    pub fn set_telemetry(&self, telemetry: Arc<dyn TelemetryAdapter>) {
        let empty = HashMap::new();
        let instruments = Instruments {
            calls: telemetry.create_counter(
                &self.metric_name("breaker.calls"),
                Some("{call}"),
                None,
                empty.clone(),
            ),
            active_calls: telemetry.create_up_down_counter(
                &self.metric_name("breaker.active_calls"),
                Some("{call}"),
                None,
                empty.clone(),
            ),
            state_gauge: telemetry.create_gauge(
                &self.metric_name("breaker.state"),
                None,
                None,
                empty.clone(),
            ),
            failures_gauge: telemetry.create_gauge(
                &self.metric_name("breaker.failures"),
                Some("{failure}"),
                None,
                empty.clone(),
            ),
            successes_gauge: telemetry.create_gauge(
                &self.metric_name("breaker.successes"),
                Some("{success}"),
                None,
                empty,
            ),
            callback_failures: None,
            fallbacks: None,
            transitions: None,
            event_timestamp: None,
        };
        *self.telemetry.lock() = Some(telemetry);
        *self.instruments.lock() = Some(instruments);
    }

    fn metric_name(&self, name: &str) -> String {
        let prefix = self.metric_prefix.trim_matches('.');
        if prefix.is_empty() {
            name.into()
        } else {
            format!("{prefix}.{name}")
        }
    }

    fn telemetry_attributes(&self, extra: &[(&str, &str)]) -> HashMap<String, String> {
        let mut map = attrs(&[("circuit_breaker.name", self.key.as_str())]);
        for (k, v) in extra {
            map.insert((*k).into(), (*v).into());
        }
        map
    }

    fn ensure_callback_failures(&self) -> Option<Arc<dyn Counter>> {
        let telemetry = self.telemetry.lock();
        let telemetry = telemetry.as_ref()?;
        let mut instruments = self.instruments.lock();
        let inst = instruments.as_mut()?;
        if inst.callback_failures.is_none() {
            inst.callback_failures = Some(telemetry.create_counter(
                &self.metric_name("breaker.callback_failures"),
                Some("{failure}"),
                None,
                HashMap::new(),
            ));
        }
        inst.callback_failures.clone()
    }

    fn ensure_fallbacks(&self) -> Option<Arc<dyn Counter>> {
        let telemetry = self.telemetry.lock();
        let telemetry = telemetry.as_ref()?;
        let mut instruments = self.instruments.lock();
        let inst = instruments.as_mut()?;
        if inst.fallbacks.is_none() {
            inst.fallbacks = Some(telemetry.create_counter(
                &self.metric_name("breaker.fallbacks"),
                Some("{fallback}"),
                None,
                HashMap::new(),
            ));
        }
        inst.fallbacks.clone()
    }

    fn ensure_transitions(&self) -> Option<Arc<dyn Counter>> {
        let telemetry = self.telemetry.lock();
        let telemetry = telemetry.as_ref()?;
        let mut instruments = self.instruments.lock();
        let inst = instruments.as_mut()?;
        if inst.transitions.is_none() {
            inst.transitions = Some(telemetry.create_counter(
                &self.metric_name("breaker.transitions"),
                Some("{transition}"),
                None,
                HashMap::new(),
            ));
        }
        inst.transitions.clone()
    }

    fn ensure_event_timestamp(&self) -> Option<Arc<dyn Gauge>> {
        let telemetry = self.telemetry.lock();
        let telemetry = telemetry.as_ref()?;
        let mut instruments = self.instruments.lock();
        let inst = instruments.as_mut()?;
        if inst.event_timestamp.is_none() {
            inst.event_timestamp = Some(telemetry.create_gauge(
                &self.metric_name("breaker.event.timestamp"),
                Some("s"),
                None,
                HashMap::new(),
            ));
        }
        inst.event_timestamp.clone()
    }

    fn unix_now() -> i64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs() as i64)
            .unwrap_or(0)
    }

    fn unix_now_f64() -> f64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.as_secs_f64())
            .unwrap_or(0.0)
    }

    fn cache_field(&self, field: &str) -> String {
        format!("{}:{field}", self.key)
    }

    fn sync_from_cache(&self) {
        let Some(cache) = &self.cache else {
            return;
        };
        let mut inner = self.inner.lock();
        inner.state = match cache.get(&self.cache_field(STATE_FIELD)) {
            Ok(Some(CacheValue::String(s))) => {
                CircuitState::try_from_str(&s).unwrap_or(CircuitState::Closed)
            }
            _ => CircuitState::Closed,
        };
        inner.failures = match cache.get(&self.cache_field(FAILURES_FIELD)) {
            Ok(Some(v)) => v.as_int().unwrap_or(0).max(0),
            _ => 0,
        };
        inner.successes = match cache.get(&self.cache_field(SUCCESSES_FIELD)) {
            Ok(Some(v)) => v.as_int().unwrap_or(0).max(0),
            _ => 0,
        };
        inner.opened_at = match cache.get(&self.cache_field(OPENED_AT_FIELD)) {
            Ok(Some(v)) => v.as_int().map(i64::from),
            _ => None,
        };
    }

    fn has_timed_out(opened_at: Option<i64>, timeout: i64) -> bool {
        opened_at.is_some_and(|t| Self::unix_now() - t >= timeout)
    }

    fn update_state(&self) {
        self.sync_from_cache();
        let inner = self.inner.lock();
        if inner.state == CircuitState::Open && Self::has_timed_out(inner.opened_at, self.timeout) {
            drop(inner);
            self.transition_to_half_open();
        }
    }

    fn set_state(&self, state: CircuitState) {
        self.inner.lock().state = state;
        if let Some(cache) = &self.cache {
            let _ = cache.set(&self.cache_field(STATE_FIELD), state.as_str().into());
        }
    }

    fn set_failures(&self, failures: i32) {
        self.inner.lock().failures = failures;
        if let Some(cache) = &self.cache {
            let _ = cache.set(&self.cache_field(FAILURES_FIELD), failures.into());
        }
    }

    fn increment_failures(&self) -> i32 {
        if let Some(cache) = &self.cache {
            let value = cache
                .increment(&self.cache_field(FAILURES_FIELD), 1)
                .unwrap_or_else(|_| {
                    let mut inner = self.inner.lock();
                    inner.failures += 1;
                    inner.failures
                });
            self.inner.lock().failures = value;
            value
        } else {
            let mut inner = self.inner.lock();
            inner.failures += 1;
            inner.failures
        }
    }

    fn set_successes(&self, successes: i32) {
        self.inner.lock().successes = successes;
        if let Some(cache) = &self.cache {
            let _ = cache.set(&self.cache_field(SUCCESSES_FIELD), successes.into());
        }
    }

    fn increment_successes(&self) -> i32 {
        if let Some(cache) = &self.cache {
            let value = cache
                .increment(&self.cache_field(SUCCESSES_FIELD), 1)
                .unwrap_or_else(|_| {
                    let mut inner = self.inner.lock();
                    inner.successes += 1;
                    inner.successes
                });
            self.inner.lock().successes = value;
            value
        } else {
            let mut inner = self.inner.lock();
            inner.successes += 1;
            inner.successes
        }
    }

    fn set_opened_at(&self, opened_at: Option<i64>) {
        self.inner.lock().opened_at = opened_at;
        let Some(cache) = &self.cache else {
            return;
        };
        let field = self.cache_field(OPENED_AT_FIELD);
        if let Some(opened_at) = opened_at {
            let value = i32::try_from(opened_at).unwrap_or(i32::MAX);
            let _ = cache.set(&field, value.into());
        } else {
            let _ = cache.delete(&field);
        }
    }

    fn record_transition(&self, from: CircuitState, to: CircuitState) {
        if from == to {
            return;
        }
        if let Some(counter) = self.ensure_transitions() {
            counter.add(
                1.0,
                &self.telemetry_attributes(&[
                    ("circuit_breaker.from_state", from.as_str()),
                    ("circuit_breaker.to_state", to.as_str()),
                ]),
            );
        }
        self.record_event(
            "transition",
            &format!("{} -> {}", from.as_str(), to.as_str()),
            &[
                ("circuit_breaker.from_state", from.as_str()),
                ("circuit_breaker.to_state", to.as_str()),
            ],
        );
    }

    fn record_event(&self, event_type: &str, name: &str, extra: &[(&str, &str)]) {
        if let Some(gauge) = self.ensure_event_timestamp() {
            let mut attrs = self.telemetry_attributes(&[
                ("circuit_breaker.event", event_type),
                ("circuit_breaker.event_name", name),
            ]);
            for (k, v) in extra {
                attrs.insert((*k).into(), (*v).into());
            }
            gauge.record(Self::unix_now_f64(), &attrs);
        }
    }

    fn record_state(&self) {
        let inner = self.inner.lock();
        if let Some(inst) = self.instruments.lock().as_ref() {
            let name = self.telemetry_attributes(&[]);
            inst.state_gauge.record(inner.state.value(), &name);
            inst.failures_gauge.record(f64::from(inner.failures), &name);
            inst.successes_gauge
                .record(f64::from(inner.successes), &name);
        }
    }

    fn transition_to_open(&self) {
        let from = self.inner.lock().state;
        self.set_opened_at(Some(Self::unix_now()));
        self.set_successes(0);
        self.set_state(CircuitState::Open);
        self.record_transition(from, CircuitState::Open);
    }

    fn transition_to_half_open(&self) {
        let from = self.inner.lock().state;
        self.set_failures(0);
        self.set_successes(0);
        self.set_state(CircuitState::HalfOpen);
        self.record_transition(from, CircuitState::HalfOpen);
    }

    fn transition_to_closed(&self) {
        let from = self.inner.lock().state;
        self.set_failures(0);
        self.set_successes(0);
        self.set_opened_at(None);
        self.set_state(CircuitState::Closed);
        self.record_transition(from, CircuitState::Closed);
    }

    fn on_success(&self) {
        let state = self.inner.lock().state;
        if state == CircuitState::HalfOpen {
            let successes = self.increment_successes();
            if successes >= self.success_threshold {
                self.transition_to_closed();
            }
        } else if state == CircuitState::Closed {
            let failures = self.inner.lock().failures;
            if failures != 0 {
                self.set_failures(0);
            }
        }
    }

    fn on_failure(&self) {
        let state = self.inner.lock().state;
        let failures = self.increment_failures();
        if state == CircuitState::HalfOpen || failures >= self.threshold {
            self.transition_to_open();
        }
    }

    /// PHP `call(open:, close:, halfOpen:)`.
    pub fn call<T, E: std::fmt::Display>(
        &self,
        open: impl FnOnce() -> T,
        close: impl FnOnce() -> Result<T, E>,
    ) -> T {
        self.call_inner(open, close, None::<fn() -> Result<T, E>>)
    }

    /// PHP `call` with `halfOpen`.
    pub fn call_half_open<T, E: std::fmt::Display>(
        &self,
        open: impl FnOnce() -> T,
        close: impl FnOnce() -> Result<T, E>,
        half_open: impl FnOnce() -> Result<T, E>,
    ) -> T {
        self.call_inner(open, close, Some(half_open))
    }

    fn call_inner<T, E: std::fmt::Display, H>(
        &self,
        open: impl FnOnce() -> T,
        close: impl FnOnce() -> Result<T, E>,
        half_open: Option<H>,
    ) -> T
    where
        H: FnOnce() -> Result<T, E>,
    {
        let mut exception_type: Option<String> = None;
        self.update_state();
        let initial = self.inner.lock().state;
        let active_attributes =
            self.telemetry_attributes(&[("circuit_breaker.state", initial.as_str())]);
        if let Some(inst) = self.instruments.lock().as_ref() {
            inst.active_calls.add(1.0, &active_attributes);
        }

        let (result, outcome) = if initial == CircuitState::Open {
            if let Some(fallbacks) = self.ensure_fallbacks() {
                fallbacks.add(
                    1.0,
                    &self.telemetry_attributes(&[
                        ("circuit_breaker.reason", "open"),
                        ("circuit_breaker.state", "open"),
                    ]),
                );
            }
            (open(), "short_circuit")
        } else {
            let use_half = initial == CircuitState::HalfOpen && half_open.is_some();
            let callback_result = if use_half {
                half_open.expect("checked")()
            } else {
                close()
            };
            match callback_result {
                Ok(value) => {
                    self.on_success();
                    (value, "success")
                }
                Err(err) => {
                    exception_type = Some(std::any::type_name::<E>().into());
                    let _ = err;
                    if let Some(counter) = self.ensure_callback_failures() {
                        let mut attrs = self
                            .telemetry_attributes(&[("circuit_breaker.state", initial.as_str())]);
                        if let Some(ty) = &exception_type {
                            attrs.insert("exception.type".into(), ty.clone());
                        }
                        counter.add(1.0, &attrs);
                    }
                    self.on_failure();
                    if let Some(fallbacks) = self.ensure_fallbacks() {
                        fallbacks.add(
                            1.0,
                            &self.telemetry_attributes(&[
                                ("circuit_breaker.reason", "failure"),
                                ("circuit_breaker.state", self.inner.lock().state.as_str()),
                            ]),
                        );
                    }
                    (open(), "fallback")
                }
            }
        };

        let state = self.inner.lock().state;
        let mut call_attrs = self.telemetry_attributes(&[
            ("circuit_breaker.initial_state", initial.as_str()),
            ("circuit_breaker.state", state.as_str()),
            ("circuit_breaker.outcome", outcome),
        ]);
        if let Some(ty) = &exception_type {
            call_attrs.insert("exception.type".into(), ty.clone());
        }
        if let Some(inst) = self.instruments.lock().as_ref() {
            inst.calls.add(1.0, &call_attrs);
        }
        if initial == CircuitState::HalfOpen {
            self.record_event(
                "probe",
                &format!("probe: {outcome}"),
                &[("circuit_breaker.outcome", outcome)],
            );
        }
        self.record_state();
        if let Some(inst) = self.instruments.lock().as_ref() {
            inst.active_calls.add(-1.0, &active_attributes);
        }
        result
    }

    #[must_use]
    pub fn get_state(&self) -> CircuitState {
        self.update_state();
        self.inner.lock().state
    }

    #[must_use]
    pub fn get_failure_count(&self) -> i32 {
        self.sync_from_cache();
        self.inner.lock().failures
    }

    #[must_use]
    pub fn get_success_count(&self) -> i32 {
        self.sync_from_cache();
        self.inner.lock().successes
    }

    #[must_use]
    pub fn is_open(&self) -> bool {
        self.get_state() == CircuitState::Open
    }

    #[must_use]
    pub fn is_closed(&self) -> bool {
        self.get_state() == CircuitState::Closed
    }

    #[must_use]
    pub fn is_half_open(&self) -> bool {
        self.get_state() == CircuitState::HalfOpen
    }

    /// PHP `trip()`.
    pub fn trip(&self) {
        self.sync_from_cache();
        self.transition_to_open();
        self.record_state();
    }
}
