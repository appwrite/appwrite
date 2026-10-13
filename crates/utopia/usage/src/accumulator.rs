//! [`Accumulator`]: metrics buffered in memory and written in batches.

use indexmap::IndexMap;
use md5::{Digest, Md5};
use php_std::datetime::DateTime;
use php_std::json::{self, Flags};
use php_std::sort::{SortFlags, ksort};
use utopia_query::{Array, Value};

use crate::adapter::{Entry, Time};
use crate::error::{Error, Result};
use crate::metric::Kind;
use crate::usage::{BATCH_SIZE, Usage};

/// A buffered metric.
#[derive(Debug, Clone)]
struct Buffered {
    entry: Entry,
    kind: Kind,
    time: Option<DateTime>,
}

/// Buffers metrics and writes them to a [`Usage`] in batches: events with
/// the same tenant, metric and tags are summed (the earliest time wins),
/// gauges keep the last write. It reports how much is buffered and for
/// how long; the caller decides when to [`Accumulator::flush`].
///
/// PHP: `Utopia\Usage\Accumulator`.
#[derive(Debug)]
pub struct Accumulator {
    usage: Usage,
    buffer: IndexMap<String, Buffered>,
    flushed_at: f64,
}

impl Accumulator {
    /// An empty accumulator writing to `usage`.
    ///
    /// PHP: `new Accumulator($usage)`.
    pub fn new(usage: Usage) -> Accumulator {
        Accumulator { usage, buffer: IndexMap::new(), flushed_at: php_std::datetime::microtime_float() }
    }

    /// Buffers one metric. Negative values are refused unless
    /// `allow_negative` (a signed delta); a folded entry keeps the opt-in
    /// of any call that folded into it.
    ///
    /// PHP: `collect($tenant, $metric, $value, $type, $tags, $time, $allowNegative)`.
    #[allow(clippy::too_many_arguments)]
    pub fn collect(
        &mut self,
        tenant: &str,
        metric: &str,
        value: i64,
        kind: Kind,
        tags: Array,
        time: Option<DateTime>,
        allow_negative: bool,
    ) -> Result<&mut Self> {
        if tenant.is_empty() {
            return Err(Error::invalid_argument("Tenant cannot be empty"));
        }
        if metric.is_empty() {
            return Err(Error::invalid_argument("Metric name cannot be empty"));
        }
        if value < 0 && !allow_negative {
            return Err(Error::invalid_argument("Value cannot be negative"));
        }

        let key = key(tenant, metric, kind, &tags)?;

        if kind == Kind::Event
            && let Some(buffered) = self.buffer.get_mut(&key)
        {
            buffered.entry.value = buffered.entry.value.saturating_add(value);
            buffered.entry.allow_negative = buffered.entry.allow_negative || allow_negative;
            if let Some(time) = time
                && buffered.time.as_ref().is_none_or(|current| time < *current)
            {
                buffered.time = Some(time);
            }
            return Ok(self);
        }

        let entry = Entry {
            tenant: Some(tenant.to_owned()),
            metric: metric.to_owned(),
            value,
            tags,
            time: None,
            allow_negative,
        };
        self.buffer.insert(key, Buffered { entry, kind, time });
        Ok(self)
    }

    /// Writes the buffer: events in one batch, gauges in another. Entries of
    /// a batch the adapter refused stay buffered; an error leaves the
    /// batches written before it cleared.
    ///
    /// PHP: `flush()`.
    pub async fn flush(&mut self) -> Result<bool> {
        if self.buffer.is_empty() {
            self.flushed_at = php_std::datetime::microtime_float();
            return Ok(true);
        }
        let mut ok = true;
        for kind in [Kind::Event, Kind::Gauge] {
            let (keys, entries): (Vec<String>, Vec<Entry>) = self
                .buffer
                .iter()
                .filter(|(_, b)| b.kind == kind)
                .map(|(k, b)| {
                    let mut entry = b.entry.clone();
                    entry.time = b.time.clone().map(Time::At);
                    (k.clone(), entry)
                })
                .unzip();
            if entries.is_empty() {
                continue;
            }
            if self.usage.add_batch(&entries, kind, BATCH_SIZE).await? {
                for key in keys {
                    self.buffer.shift_remove(&key);
                }
            } else {
                ok = false;
            }
        }
        if self.buffer.is_empty() {
            self.flushed_at = php_std::datetime::microtime_float();
        }
        Ok(ok)
    }

    /// How many distinct entries are buffered.
    ///
    /// PHP: `count()`.
    pub fn count(&self) -> usize {
        self.buffer.len()
    }

    /// Seconds since the last flush that emptied the buffer.
    ///
    /// PHP: `elapsedSeconds()`.
    pub fn elapsed_seconds(&self) -> f64 {
        php_std::datetime::microtime_float() - self.flushed_at
    }

    /// The buffered entries and their kinds, in buffer order (the time an
    /// entry will be written with is its [`Entry::time`]).
    pub fn entries(&self) -> impl Iterator<Item = (Entry, Kind)> + '_ {
        self.buffer.values().map(|b| {
            let mut entry = b.entry.clone();
            entry.time = b.time.clone().map(Time::At);
            (entry, b.kind)
        })
    }
}

/// `md5(json_encode([$tenant, $metric, $type, ksort($tags)]))`: the
/// identity of an entry, tags in key order.
fn key(tenant: &str, metric: &str, kind: Kind, tags: &Array) -> Result<String> {
    let mut canonical = tags.clone();
    ksort(&mut canonical, SortFlags::Regular);
    let identity = Value::Array(Array::from_list(vec![
        Value::from(tenant),
        Value::from(metric),
        Value::from(kind.as_str()),
        Value::Array(canonical),
    ]));
    let encoded = json::encode(&identity, Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
        .map_err(|e| Error::Json(e.message().into()))?;
    Ok(hex::encode(Md5::digest(encoded.as_bytes())))
}
