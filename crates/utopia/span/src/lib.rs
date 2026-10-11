//! Span tracing: record what one unit of work (a request, a job, a query) did
//! as attributes on a span, and export the span when it finishes.
//!
//! [`Span::init`] starts a span and makes it the current span of the running
//! task, so code anywhere below can add attributes with [`Span::add`] without
//! holding it. [`Span::finish`] stamps the duration and level and hands the
//! span to every registered [`Exporter`]: [`Stdout`] (JSON lines), [`Pretty`]
//! (colourful terminal output) or [`Sentry`] (errors as Sentry issues). Spans
//! carry W3C trace context, so a trace continues across services
//! ([`Span::traceparent`], [`Span::init`] with a `traceparent` header).
//!
//! Where "current" lives is a [`Storage`]: [`Memory`] (one span per process),
//! [`Task`] (one span per tokio task) or [`Auto`] (the task's span inside a
//! task scope, the process's otherwise), chosen once with
//! [`Span::set_storage`].
//!
//! PHP: ports `utopia-php/span`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Span` (`new Span($action)`) | [`Span`] ([`Span::new`]) |
//! | `Span::setStorage()`, `Span::setExporters()` | [`Span::set_storage`], [`Span::set_exporters`] |
//! | `Span::init()`, `Span::current()`, `Span::add()`, `Span::traceparent()` | [`Span::init`], [`Span::current`], [`Span::add`], [`Span::current_traceparent`] |
//! | `set()`, `get()`, `getAttributes()`, `getAction()`, `getTraceparent()` | [`Span::set`], [`Span::get`], [`Span::attributes`], [`Span::action`], [`Span::traceparent`] |
//! | `setError()`, `getError()`, `finish()` | [`Span::set_error`], [`Span::error`], [`Span::finish`] / [`Span::finish_with`] |
//! | `string\|int\|float\|bool\|null` attribute values | [`Value`] |
//! | `\Throwable` | [`Failure`] |
//! | `Level` | [`Level`] |
//! | `Storage\Storage`, `Memory`, `Coroutine`, `Auto` | [`Storage`], [`Memory`], [`Task`], [`Auto`] |
//! | `Exporter\Exporter`, `None`, `Stdout`, `Pretty` | [`Exporter`], [`exporter::None`], [`Stdout`], [`Pretty`] |
//! | `Exporter\Sentry`, `SentryField`, `Sentry\Level` | [`Sentry`], [`exporter::SentryField`], [`exporter::SentryLevel`] |
//!
//! PHP: storage and exporters are static properties of `Span`; here they are
//! process-wide too. A [`Span`] is a shared handle, as a PHP object is: every
//! clone is the same span ([`Span::ptr_eq`]).
//!
//! ```
//! use utopia_span::{Memory, Span};
//! use std::sync::Arc;
//!
//! Span::set_storage(Some(Arc::new(Memory::new())));
//!
//! let span = Span::init("http.request", None);
//! Span::add("http.method", "GET");
//! Span::add("http.response.status_code", 200);
//!
//! assert_eq!(span.get("http.method"), "GET".into());
//! assert_eq!(span.get("http.response.status_code"), 200.into());
//! span.finish();
//! assert!(Span::current().is_none());
//! ```
//!
//! ```php
//! use Utopia\Span\Span;
//! use Utopia\Span\Storage\Memory;
//!
//! Span::setStorage(new Memory());
//!
//! $span = Span::init('http.request');
//! Span::add('http.method', 'GET');
//! Span::add('http.response.status_code', 200);
//!
//! echo $span->get('http.method'), "\n";               // GET
//! echo $span->get('http.response.status_code'), "\n"; // 200
//! $span->finish();
//! var_dump(Span::current());                           // NULL
//! ```

#![warn(missing_docs)]

pub mod exporter;
mod failure;
mod storage;
mod value;

use std::sync::{Arc, Mutex, MutexGuard, RwLock};
use std::time::{SystemTime, UNIX_EPOCH};

pub use exporter::{Exporter, Pretty, Sentry, Stdout};
pub use failure::{Failure, Frame};
pub use storage::{Auto, Memory, Storage, Task};
pub use value::Value;

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;

/// Why an exporter could not be created.
#[derive(Debug, Clone, PartialEq, Eq, thiserror::Error)]
pub enum Error {
    /// An argument is invalid, such as a Sentry DSN without a project id.
    ///
    /// PHP: `\InvalidArgumentException`, with the same message.
    #[error("{0}")]
    InvalidArgument(&'static str),
}

impl Error {
    /// The name of the PHP exception class this error matches.
    ///
    /// PHP: the class `utopia-php/span` throws.
    ///
    /// ```
    /// use utopia_span::Error;
    ///
    /// assert_eq!(Error::InvalidArgument("Sentry DSN is required").php_class(), "InvalidArgumentException");
    /// ```
    pub fn php_class(&self) -> &'static str {
        match self {
            Error::InvalidArgument(_) => "InvalidArgumentException",
        }
    }
}

/// The severity of a span, from least to most severe.
///
/// PHP: `Utopia\Span\Level`. Names follow Grafana Loki's `detected_level`
/// vocabulary (`warn`, not `warning`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub enum Level {
    /// `debug`.
    Debug,
    /// `info`: the level of a span finished without an error.
    Info,
    /// `warn`.
    Warn,
    /// `error`: the level of a span finished with an error.
    Error,
    /// `fatal`.
    Fatal,
}

impl Level {
    /// Every level, from least to most severe.
    ///
    /// PHP: `Level::cases()`.
    pub const ALL: [Level; 5] = [Level::Debug, Level::Info, Level::Warn, Level::Error, Level::Fatal];

    /// The level's name, as the `level` attribute holds it.
    ///
    /// PHP: `$level->value`.
    ///
    /// ```
    /// use utopia_span::Level;
    ///
    /// assert_eq!(Level::Warn.as_str(), "warn");
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Level;
    ///
    /// echo Level::Warn->value, "\n"; // warn
    /// ```
    pub const fn as_str(self) -> &'static str {
        match self {
            Level::Debug => "debug",
            Level::Info => "info",
            Level::Warn => "warn",
            Level::Error => "error",
            Level::Fatal => "fatal",
        }
    }

    /// The level named `name`, if any.
    ///
    /// PHP: `Level::tryFrom($name)` (`Level::from()` throws a `\ValueError`
    /// where this returns `None`).
    ///
    /// ```
    /// use utopia_span::Level;
    ///
    /// assert_eq!(Level::parse(b"error"), Some(Level::Error));
    /// assert_eq!(Level::parse(b"warning"), None);
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Level;
    ///
    /// var_dump(Level::tryFrom('error') === Level::Error); // bool(true)
    /// var_dump(Level::tryFrom('warning'));                 // NULL
    /// ```
    pub fn parse(name: &[u8]) -> Option<Level> {
        Level::ALL.into_iter().find(|l| l.as_str().as_bytes() == name)
    }
}

impl std::fmt::Display for Level {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(self.as_str())
    }
}

static STORAGE: RwLock<Option<Arc<dyn Storage>>> = RwLock::new(None);
static EXPORTERS: RwLock<Vec<Arc<dyn Exporter>>> = RwLock::new(Vec::new());

struct State {
    /// Attributes in insertion order; setting a key again keeps its place.
    attributes: Vec<(Box<str>, Value)>,
    error: Option<Arc<Failure>>,
}

struct Inner {
    action: Box<str>,
    state: Mutex<State>,
}

/// One unit of work: an action, attributes, and the error it failed with, if
/// any.
///
/// A new span has a random 128-bit trace id (`span.trace_id`), a random
/// 64-bit span id (`span.id`), both in lowercase hex, and its start time in
/// seconds since the epoch (`span.started_at`). A `Span` is a handle: clones
/// share one span.
///
/// PHP: `Utopia\Span\Span`.
///
/// ```
/// use utopia_span::{Span, Value};
///
/// let span = Span::new("db.query");
/// span.set("db.table", "users").set("db.rows", 3);
///
/// assert_eq!(span.action(), "db.query");
/// assert_eq!(span.get("db.rows"), Value::Int(3));
/// assert_eq!(span.get("missing"), Value::Null);
/// assert_eq!(span.get("span.trace_id").as_str().map(str::len), Some(32));
/// ```
///
/// ```php
/// use Utopia\Span\Span;
///
/// $span = new Span('db.query');
/// $span->set('db.table', 'users')->set('db.rows', 3);
///
/// echo $span->getAction(), "\n";                    // db.query
/// echo $span->get('db.rows'), "\n";                 // 3
/// var_dump($span->get('missing'));                  // NULL
/// echo strlen($span->get('span.trace_id')), "\n";  // 32
/// ```
#[derive(Clone)]
pub struct Span(Arc<Inner>);

impl std::fmt::Debug for Span {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let state = self.state();
        f.debug_struct("Span").field("action", &self.0.action).field("attributes", &state.attributes).finish()
    }
}

impl PartialEq for Span {
    /// Whether both handles are the same span (PHP's `===`).
    fn eq(&self, other: &Self) -> bool {
        Arc::ptr_eq(&self.0, &other.0)
    }
}

impl Default for Span {
    /// A span of the action `unknown`.
    fn default() -> Self {
        Span::new("unknown")
    }
}

fn hex(bytes: &[u8]) -> String {
    const DIGITS: &[u8; 16] = b"0123456789abcdef";
    let mut out = String::with_capacity(bytes.len() * 2);
    for b in bytes {
        out.push(DIGITS[usize::from(b >> 4)] as char);
        out.push(DIGITS[usize::from(b & 15)] as char);
    }
    out
}

/// Seconds since the epoch, as PHP's `microtime(true)` (microseconds).
fn microtime() -> f64 {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    now.as_secs() as f64 + f64::from(now.subsec_micros()) / 1e6
}

impl Span {
    /// A new span of `action`, with new trace and span ids, started now. It
    /// is not the current span: [`Span::init`] makes one current.
    ///
    /// PHP: `new Span($action)`.
    pub fn new(action: impl Into<Box<str>>) -> Span {
        let mut ids = [0u8; 24];
        // The OS random source does not fail on supported platforms; zero ids
        // would still be valid ones.
        let _ = getrandom::fill(&mut ids);
        let attributes = vec![
            (Box::from("span.trace_id"), Value::from(hex(&ids[..16]))),
            (Box::from("span.id"), Value::from(hex(&ids[16..]))),
            (Box::from("span.started_at"), Value::Float(microtime())),
        ];
        Span(Arc::new(Inner { action: action.into(), state: Mutex::new(State { attributes, error: None }) }))
    }

    fn state(&self) -> MutexGuard<'_, State> {
        self.0.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Whether `a` and `b` are the same span.
    ///
    /// PHP: `$a === $b`.
    pub fn ptr_eq(a: &Span, b: &Span) -> bool {
        Arc::ptr_eq(&a.0, &b.0)
    }

    /// Sets (or clears, with `None`) where the current span is kept. Call it
    /// once at startup, before creating spans.
    ///
    /// PHP: `Span::setStorage($storage)`.
    pub fn set_storage(storage: Option<Arc<dyn Storage>>) {
        *STORAGE.write().unwrap_or_else(|e| e.into_inner()) = storage;
    }

    /// Replaces every exporter. Finished spans go to each exporter whose
    /// [`Exporter::sample`] accepts them.
    ///
    /// PHP: `Span::setExporters(...$exporters)`.
    pub fn set_exporters(exporters: Vec<Arc<dyn Exporter>>) {
        *EXPORTERS.write().unwrap_or_else(|e| e.into_inner()) = exporters;
    }

    /// Starts a span of `action` and makes it the current span.
    ///
    /// A valid W3C `traceparent` header (`00-<32 hex>-<16 hex>-<2 hex>`)
    /// continues its trace: the span takes its trace id, and its parent id
    /// becomes `span.parent_id`. Anything else is ignored and the span starts
    /// a new trace.
    ///
    /// PHP: `Span::init($action, $traceparent)`.
    ///
    /// ```
    /// use utopia_span::{Span, Value};
    ///
    /// let span = Span::init("http.request", Some("00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01"));
    /// assert_eq!(span.get("span.trace_id"), Value::from("0af7651916cd43dd8448eb211c80319c"));
    /// assert_eq!(span.get("span.parent_id"), Value::from("b7ad6b7169203331"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Span;
    ///
    /// $span = Span::init('http.request', '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01');
    /// echo $span->get('span.trace_id'), "\n";  // 0af7651916cd43dd8448eb211c80319c
    /// echo $span->get('span.parent_id'), "\n"; // b7ad6b7169203331
    /// ```
    pub fn init(action: impl Into<Box<str>>, traceparent: Option<&str>) -> Span {
        let span = Span::new(action);
        if let Some(traceparent) = traceparent {
            let parts: Vec<&str> = traceparent.split('-').collect();
            let xdigits = |s: &str, n: usize| s.len() == n && s.bytes().all(|b| b.is_ascii_hexdigit());
            if parts.len() == 4
                && parts[0] == "00"
                && xdigits(parts[1], 32)
                && xdigits(parts[2], 16)
                && xdigits(parts[3], 2)
            {
                span.set("span.trace_id", parts[1]);
                span.set("span.parent_id", parts[2]);
            }
        }
        if let Some(storage) = STORAGE.read().unwrap_or_else(|e| e.into_inner()).as_ref() {
            storage.set(Some(span.clone()));
        }
        span
    }

    /// The current span, if one is active.
    ///
    /// PHP: `Span::current()`.
    pub fn current() -> Option<Span> {
        STORAGE.read().unwrap_or_else(|e| e.into_inner()).as_ref()?.get()
    }

    /// Sets an attribute on the current span; does nothing when no span is
    /// active.
    ///
    /// PHP: `Span::add($key, $value)`.
    ///
    /// ```
    /// use std::sync::Arc;
    /// use utopia_span::{Memory, Span, Value};
    ///
    /// Span::set_storage(Some(Arc::new(Memory::new())));
    /// Span::add("ignored", 1); // no current span yet
    ///
    /// let span = Span::init("job", None);
    /// Span::add("job.id", "a1");
    /// assert_eq!(span.get("job.id"), Value::from("a1"));
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Span;
    /// use Utopia\Span\Storage\Memory;
    ///
    /// Span::setStorage(new Memory());
    /// Span::add('ignored', 1); // no current span yet
    ///
    /// $span = Span::init('job');
    /// Span::add('job.id', 'a1');
    /// echo $span->get('job.id'), "\n"; // a1
    /// ```
    pub fn add(key: &str, value: impl Into<Value>) {
        if let Some(span) = Span::current() {
            span.set(key, value);
        }
    }

    /// The `traceparent` header of the current span, to propagate the trace
    /// to the services this one calls.
    ///
    /// PHP: `Span::traceparent()`.
    pub fn current_traceparent() -> Option<String> {
        Span::current().map(|s| s.traceparent())
    }

    /// Sets an attribute. Setting a key again replaces its value in place;
    /// the built-in `span.*` attributes can be replaced too.
    ///
    /// PHP: `$span->set($key, $value)`.
    pub fn set(&self, key: &str, value: impl Into<Value>) -> &Self {
        let value = value.into();
        let mut state = self.state();
        match state.attributes.iter_mut().find(|(k, _)| &**k == key) {
            Some((_, v)) => *v = value,
            None => state.attributes.push((Box::from(key), value)),
        }
        self
    }

    /// Records the error this span failed with. Exporters read it with
    /// [`Span::error`].
    ///
    /// PHP: `$span->setError($throwable)`.
    pub fn set_error(&self, error: impl Into<Arc<Failure>>) -> &Self {
        self.state().error = Some(error.into());
        self
    }

    /// The recorded error, if any.
    ///
    /// PHP: `$span->getError()`.
    pub fn error(&self) -> Option<Arc<Failure>> {
        self.state().error.clone()
    }

    /// What the span represents (`http.request`, `db.query`).
    ///
    /// PHP: `$span->getAction()`.
    pub fn action(&self) -> &str {
        &self.0.action
    }

    /// An attribute's value; [`Value::Null`] when it is not set.
    ///
    /// PHP: `$span->get($key)`.
    pub fn get(&self, key: &str) -> Value {
        self.state().attributes.iter().find(|(k, _)| &**k == key).map(|(_, v)| v.clone()).unwrap_or(Value::Null)
    }

    /// The span's W3C `traceparent` header: `00-{trace id}-{span id}-01`.
    ///
    /// PHP: `$span->getTraceparent()`.
    ///
    /// ```
    /// use utopia_span::Span;
    ///
    /// let span = Span::new("call");
    /// span.set("span.trace_id", "0af7651916cd43dd8448eb211c80319c").set("span.id", "b7ad6b7169203331");
    /// assert_eq!(span.traceparent(), "00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01");
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Span;
    ///
    /// $span = new Span('call');
    /// $span->set('span.trace_id', '0af7651916cd43dd8448eb211c80319c')->set('span.id', 'b7ad6b7169203331');
    /// echo $span->getTraceparent(), "\n"; // 00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01
    /// ```
    pub fn traceparent(&self) -> String {
        let text = |v: Value| String::from_utf8_lossy(&v.to_php_string()).into_owned();
        format!("00-{}-{}-01", text(self.get("span.trace_id")), text(self.get("span.id")))
    }

    /// Every attribute, in the order they were first set.
    ///
    /// PHP: `$span->getAttributes()`.
    pub fn attributes(&self) -> Vec<(Box<str>, Value)> {
        self.state().attributes.clone()
    }

    /// Calls `f` with every attribute, in order, without copying them.
    pub fn with_attributes<R>(&self, f: impl FnOnce(&[(Box<str>, Value)]) -> R) -> R {
        f(&self.state().attributes)
    }

    /// Finishes the span: [`Span::finish_with`] without a level or error.
    ///
    /// PHP: `$span->finish()`.
    pub fn finish(&self) {
        self.finish_with(None, None);
    }

    /// Finishes the span and exports it.
    ///
    /// Records `error` (if given), sets `span.finished_at` and
    /// `span.duration` (seconds), and sets `level` to `level`, or else to
    /// [`Level::Error`] when the span has an error and [`Level::Info`]
    /// otherwise, replacing any `level` set before. Then every exporter whose
    /// sampler accepts the span exports it, and the current span is cleared.
    ///
    /// PHP: `$span->finish($level, $error)`.
    ///
    /// ```
    /// use utopia_span::{Failure, Level, Span, Value};
    ///
    /// let span = Span::new("job");
    /// span.finish_with(Some(Level::Warn), Some(Failure::new("RuntimeException", "slow")));
    /// assert_eq!(span.get("level"), Value::from("warn"));
    /// assert!(span.get("span.duration").as_float().is_some());
    /// ```
    ///
    /// ```php
    /// use Utopia\Span\Level;
    /// use Utopia\Span\Span;
    ///
    /// $span = new Span('job');
    /// $span->finish(level: Level::Warn, error: new \RuntimeException('slow'));
    /// echo $span->get('level'), "\n";              // warn
    /// var_dump(is_float($span->get('span.duration'))); // bool(true)
    /// ```
    pub fn finish_with(&self, level: Option<Level>, error: Option<Failure>) {
        if let Some(error) = error {
            self.set_error(error);
        }
        let finished = microtime();
        let started = self.get("span.started_at").to_number();
        self.set("span.finished_at", finished);
        self.set("span.duration", finished - started);
        let level = level.unwrap_or(if self.error().is_some() { Level::Error } else { Level::Info });
        self.set("level", level.as_str());

        let exporters = EXPORTERS.read().unwrap_or_else(|e| e.into_inner()).clone();
        for exporter in &exporters {
            if exporter.sample(self) {
                exporter.export(self);
            }
        }
        if let Some(storage) = STORAGE.read().unwrap_or_else(|e| e.into_inner()).as_ref() {
            storage.set(None);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn invalid_traceparent_starts_a_new_trace() {
        let span = Span::init("t", Some("00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331"));
        assert_eq!(span.get("span.parent_id"), Value::Null);
        assert_eq!(span.get("span.id").as_str().map(str::len), Some(16));
    }

    #[test]
    fn set_keeps_the_position() {
        let span = Span::new("t");
        span.set("a", 1).set("b", 2).set("a", 3);
        let keys: Vec<_> = span.attributes().into_iter().map(|(k, _)| k).collect();
        assert_eq!(&*keys[3], "a");
        assert_eq!(span.get("a"), Value::Int(3));
    }
}
