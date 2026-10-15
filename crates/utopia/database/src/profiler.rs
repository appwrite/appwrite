//! [`Profiler`]: a ring of the statements an adapter ran, with their
//! timings, slow-query detection and N+1 pattern detection.
//!
//! PHP: `Utopia\Database\Profiler`, `Profiler\Log`.

use std::sync::{Arc, Mutex, MutexGuard};

use crate::error::{Error, Kind, Result};
use crate::value::Array;

/// One statement a profiler recorded.
///
/// PHP: `Utopia\Database\Profiler\Log`.
#[derive(Debug, Clone, PartialEq)]
pub struct Log {
    /// The statement.
    pub query: String,
    /// Its bindings.
    pub bindings: Array,
    /// How long it ran, in milliseconds.
    pub duration_ms: f64,
    /// The collection it read or wrote.
    pub collection: String,
    /// The operation that ran it.
    pub operation: String,
    /// Where it was run from, when backtraces are captured.
    pub backtrace: Option<Vec<String>>,
}

/// What a slow query is reported to.
pub type SlowQueryCallback = Arc<dyn Fn(&Log) + Send + Sync>;

struct Inner {
    logs: Vec<Log>,
    oldest: usize,
    capacity: usize,
    query_count: i64,
    total_time: f64,
    slow_threshold: f64,
    enabled: bool,
    capture_backtrace: bool,
    on_slow_query: Option<SlowQueryCallback>,
}

/// Records the statements an adapter runs: the last
/// [`Profiler::capacity`] of them, how many ran and for how long.
///
/// ```
/// use utopia_database::Profiler;
///
/// let profiler = Profiler::new();
/// profiler.enable();
/// for id in 0..6 {
///     profiler.log(&format!("SELECT * FROM t WHERE id = {id}"), Default::default(), 1.0, "", "");
/// }
/// assert_eq!(profiler.query_count(), 6);
/// assert_eq!(profiler.detect_n_plus_one(5), vec![("SELECT * FROM t WHERE id = ?".to_owned(), 6)]);
/// ```
///
/// PHP: `Utopia\Database\Profiler`; shared between a database and its
/// adapter, so its state is behind a lock.
pub struct Profiler {
    inner: Mutex<Inner>,
}

impl std::fmt::Debug for Profiler {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        let inner = self.lock();
        f.debug_struct("Profiler")
            .field("enabled", &inner.enabled)
            .field("capacity", &inner.capacity)
            .field("query_count", &inner.query_count)
            .finish()
    }
}

impl Default for Profiler {
    fn default() -> Self {
        Profiler::new()
    }
}

impl Profiler {
    /// How many statements a profiler keeps by default (`DEFAULT_CAPACITY`).
    pub const DEFAULT_CAPACITY: usize = 1000;

    /// A disabled profiler with the default capacity and a slow threshold
    /// of 100 ms.
    pub fn new() -> Self {
        Profiler {
            inner: Mutex::new(Inner {
                logs: Vec::new(),
                oldest: 0,
                capacity: Self::DEFAULT_CAPACITY,
                query_count: 0,
                total_time: 0.0,
                slow_threshold: 100.0,
                enabled: false,
                capture_backtrace: false,
                on_slow_query: None,
            }),
        }
    }

    fn lock(&self) -> MutexGuard<'_, Inner> {
        self.inner.lock().unwrap_or_else(|e| e.into_inner())
    }

    /// Starts recording (`enable()`).
    pub fn enable(&self) -> &Self {
        self.lock().enabled = true;
        self
    }

    /// Stops recording (`disable()`).
    pub fn disable(&self) -> &Self {
        self.lock().enabled = false;
        self
    }

    /// Whether it records (`isEnabled()`).
    pub fn is_enabled(&self) -> bool {
        self.lock().enabled
    }

    /// Sets the duration from which a statement is slow (`setSlowThreshold()`).
    pub fn set_slow_threshold(&self, milliseconds: f64) -> &Self {
        self.lock().slow_threshold = milliseconds;
        self
    }

    /// Keeps the last `capacity` statements (`setCapacity()`).
    ///
    /// Fails ([`Kind::Exception`], PHP's `InvalidArgumentException`) for a
    /// capacity below 1 (`Profiler capacity must be at least 1`).
    pub fn set_capacity(&self, capacity: i64) -> Result<&Self> {
        if capacity < 1 {
            return Err(Error::new(Kind::Exception, "Profiler capacity must be at least 1"));
        }
        let capacity = usize::try_from(capacity).unwrap_or(usize::MAX);
        let mut inner = self.lock();
        let logs = Self::ordered(&inner);
        let skip = logs.len().saturating_sub(capacity);
        inner.logs = logs.into_iter().skip(skip).collect();
        inner.oldest = 0;
        inner.capacity = capacity;
        drop(inner);
        Ok(self)
    }

    /// How many statements it keeps (`getCapacity()`).
    pub fn capacity(&self) -> usize {
        self.lock().capacity
    }

    /// Captures where each statement is run from (`enableBacktrace()`).
    pub fn enable_backtrace(&self, enabled: bool) -> &Self {
        self.lock().capture_backtrace = enabled;
        self
    }

    /// Reports each slow statement to `callback` (`onSlowQuery()`).
    pub fn on_slow_query(&self, callback: impl Fn(&Log) + Send + Sync + 'static) -> &Self {
        self.lock().on_slow_query = Some(Arc::new(callback));
        self
    }

    /// Records a statement, when enabled (`log()`).
    pub fn log(&self, query: &str, bindings: Array, duration_ms: f64, collection: &str, operation: &str) {
        let (entry, callback) = {
            let mut inner = self.lock();
            if !inner.enabled {
                return;
            }
            let backtrace = inner.capture_backtrace.then(|| {
                std::backtrace::Backtrace::force_capture()
                    .to_string()
                    .lines()
                    .filter(|l| l.trim_start().starts_with("at "))
                    .take(10)
                    .map(|l| l.trim().trim_start_matches("at ").to_owned())
                    .collect()
            });
            let entry = Log {
                query: query.to_owned(),
                bindings,
                duration_ms,
                collection: collection.to_owned(),
                operation: operation.to_owned(),
                backtrace,
            };
            inner.query_count += 1;
            inner.total_time += duration_ms;
            if inner.logs.len() < inner.capacity {
                inner.logs.push(entry.clone());
            } else {
                let oldest = inner.oldest;
                inner.logs[oldest] = entry.clone();
                inner.oldest = (oldest + 1) % inner.capacity;
            }
            let callback = (duration_ms >= inner.slow_threshold).then(|| inner.on_slow_query.clone()).flatten();
            (entry, callback)
        };
        if let Some(callback) = callback {
            callback(&entry);
        }
    }

    fn ordered(inner: &Inner) -> Vec<Log> {
        let mut logs = inner.logs[inner.oldest..].to_vec();
        logs.extend_from_slice(&inner.logs[..inner.oldest]);
        logs
    }

    /// The recorded statements, oldest first (`getLogs()`).
    pub fn logs(&self) -> Vec<Log> {
        Self::ordered(&self.lock())
    }

    /// The recorded statements at least as slow as the threshold, oldest
    /// first, with their positions in [`Profiler::logs`] (`getSlowQueries()`,
    /// which keeps the keys).
    pub fn slow_queries(&self) -> Vec<(usize, Log)> {
        let inner = self.lock();
        let threshold = inner.slow_threshold;
        Self::ordered(&inner).into_iter().enumerate().filter(|(_, l)| l.duration_ms >= threshold).collect()
    }

    /// How many statements were recorded (`getQueryCount()`).
    pub fn query_count(&self) -> i64 {
        self.lock().query_count
    }

    /// How long the recorded statements ran, in milliseconds (`getTotalTime()`).
    pub fn total_time(&self) -> f64 {
        self.lock().total_time
    }

    /// The statement patterns seen at least `threshold` times, with their
    /// counts, in the order first seen (`detectNPlusOne()`): placeholder
    /// lists become `?...`, quoted strings and numbers `?`.
    pub fn detect_n_plus_one(&self, threshold: i64) -> Vec<(String, i64)> {
        let inner = self.lock();
        let mut patterns: Vec<(String, i64)> = Vec::new();
        for log in &inner.logs {
            let pattern = digits(&quoted(&placeholders(&log.query)));
            match patterns.iter_mut().find(|(p, _)| *p == pattern) {
                Some((_, count)) => *count += 1,
                None => patterns.push((pattern, 1)),
            }
        }
        patterns.into_iter().filter(|(_, count)| *count >= threshold).collect()
    }

    /// Forgets the recorded statements and the totals (`reset()`).
    pub fn reset(&self) {
        let mut inner = self.lock();
        inner.logs.clear();
        inner.oldest = 0;
        inner.query_count = 0;
        inner.total_time = 0.0;
    }
}

/// `preg_replace('/\?(?:,\s*\?)*/', '?...', $query)`.
fn placeholders(query: &str) -> String {
    let b = query.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] != b'?' {
            out.push(b[i]);
            i += 1;
            continue;
        }
        i += 1;
        loop {
            // `,\s*\?`
            if i < b.len() && b[i] == b',' {
                let mut j = i + 1;
                while j < b.len() && matches!(b[j], b' ' | b'\t' | b'\n' | b'\r' | b'\x0B' | b'\x0C') {
                    j += 1;
                }
                if j < b.len() && b[j] == b'?' {
                    i = j + 1;
                    continue;
                }
            }
            break;
        }
        out.extend_from_slice(b"?...");
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `preg_replace("/'[^']*'/", '?', $pattern)`.
fn quoted(pattern: &str) -> String {
    let b = pattern.as_bytes();
    let mut out = Vec::with_capacity(b.len());
    let mut i = 0;
    while i < b.len() {
        if b[i] == b'\''
            && let Some(end) = b[i + 1..].iter().position(|&c| c == b'\'')
        {
            out.push(b'?');
            i += end + 2;
            continue;
        }
        out.push(b[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// `preg_replace('/\d+/', '?', $pattern)`.
fn digits(pattern: &str) -> String {
    let mut out = String::with_capacity(pattern.len());
    let mut in_digits = false;
    for c in pattern.chars() {
        if c.is_ascii_digit() {
            if !in_digits {
                out.push('?');
                in_digits = true;
            }
        } else {
            in_digits = false;
            out.push(c);
        }
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn patterns() {
        assert_eq!(placeholders("IN (?, ?,?) AND x = ?"), "IN (?...) AND x = ?...");
        assert_eq!(quoted("a = 'x' AND b = 'y'"), "a = ? AND b = ?");
        assert_eq!(digits("LIMIT 25 OFFSET 100"), "LIMIT ? OFFSET ?");
    }

    #[test]
    fn ring_keeps_the_latest() {
        let p = Profiler::new();
        p.enable();
        p.set_capacity(2).unwrap();
        for q in ["a", "b", "c"] {
            p.log(q, Array::new(), 1.0, "", "");
        }
        assert_eq!(p.logs().iter().map(|l| l.query.as_str()).collect::<Vec<_>>(), ["b", "c"]);
        assert_eq!(p.set_capacity(0).unwrap_err().message(), "Profiler capacity must be at least 1");
    }
}
