//! In-memory metric accumulator. PHP `Utopia\Usage\Accumulator`.

use std::collections::BTreeMap;
use std::time::Instant;

use md5;
use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::{Result, UsageError};
use crate::usage::Usage;

struct Entry {
    tenant: String,
    metric: String,
    value: i64,
    type_: String,
    tags: Map<String, Value>,
    allow_negative: bool,
    time: Option<String>,
}

#[allow(missing_debug_implementations)]
pub struct Accumulator<A: Adapter> {
    usage: Usage<A>,
    buffer: BTreeMap<String, Entry>,
    flushed_at: Instant,
}

impl<A: Adapter> std::fmt::Debug for Accumulator<A> {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Accumulator")
            .field("buffer_len", &self.buffer.len())
            .field("flushed_at", &self.flushed_at)
            .finish_non_exhaustive()
    }
}

impl<A: Adapter> Accumulator<A> {
    pub fn new(usage: Usage<A>) -> Self {
        Self {
            usage,
            buffer: BTreeMap::new(),
            flushed_at: Instant::now(),
        }
    }

    pub fn collect(
        &mut self,
        tenant: impl Into<String>,
        metric: impl Into<String>,
        value: i64,
        type_: impl Into<String>,
        tags: Map<String, Value>,
        time: Option<String>,
        allow_negative: bool,
    ) -> Result<&mut Self> {
        let tenant = tenant.into();
        let metric = metric.into();
        let type_ = type_.into();
        if tenant.is_empty() {
            return Err(UsageError::message("Tenant cannot be empty"));
        }
        if metric.is_empty() {
            return Err(UsageError::message("Metric name cannot be empty"));
        }
        if value < 0 && !allow_negative {
            return Err(UsageError::message("Value cannot be negative"));
        }
        if type_ != Usage::<A>::TYPE_EVENT && type_ != Usage::<A>::TYPE_GAUGE {
            return Err(UsageError::message(format!(
                "Invalid metric type '{type_}'. Allowed: {}, {}",
                Usage::<A>::TYPE_EVENT,
                Usage::<A>::TYPE_GAUGE
            )));
        }
        let mut canonical = tags.clone();
        let keys: Vec<String> = canonical.keys().cloned().collect();
        // already BTree-ish via Map preserve_order - sort by serializing sorted keys
        let mut sorted = Map::new();
        let mut ks: Vec<_> = keys;
        ks.sort();
        for k in ks {
            if let Some(v) = canonical.remove(&k) {
                sorted.insert(k, v);
            }
        }
        let payload = json!([tenant, metric, type_, sorted]);
        let digest = md5::compute(serde_json::to_vec(&payload).unwrap_or_default());
        let key = format!("{digest:x}");
        if type_ == Usage::<A>::TYPE_EVENT {
            if let Some(existing) = self.buffer.get_mut(&key) {
                existing.value += value;
                existing.allow_negative = existing.allow_negative || allow_negative;
                if let Some(t) = time {
                    if existing.time.as_ref().map_or(true, |old| t < *old) {
                        existing.time = Some(t);
                    }
                }
                return Ok(self);
            }
        }
        self.buffer.insert(
            key,
            Entry {
                tenant,
                metric,
                value,
                type_,
                tags,
                allow_negative,
                time,
            },
        );
        Ok(self)
    }

    pub fn flush(&mut self) -> Result<bool> {
        if self.buffer.is_empty() {
            self.flushed_at = Instant::now();
            return Ok(true);
        }
        let mut events = Vec::new();
        let mut event_keys = Vec::new();
        let mut gauges = Vec::new();
        let mut gauge_keys = Vec::new();
        for (key, entry) in &self.buffer {
            let mut row = Map::new();
            row.insert("tenant".into(), json!(entry.tenant));
            row.insert("metric".into(), json!(entry.metric));
            row.insert("value".into(), json!(entry.value));
            row.insert("type".into(), json!(entry.type_));
            row.insert("tags".into(), Value::Object(entry.tags.clone()));
            row.insert("allowNegative".into(), json!(entry.allow_negative));
            if let Some(t) = &entry.time {
                row.insert("time".into(), json!(t));
            }
            if entry.type_ == Usage::<A>::TYPE_EVENT {
                events.push(row);
                event_keys.push(key.clone());
            } else {
                gauges.push(row);
                gauge_keys.push(key.clone());
            }
        }
        let mut overall = true;
        if !events.is_empty() {
            if self.usage.add_batch(events, Usage::<A>::TYPE_EVENT, 1000)? {
                for k in event_keys {
                    self.buffer.remove(&k);
                }
            } else {
                overall = false;
            }
        }
        if !gauges.is_empty() {
            if self.usage.add_batch(gauges, Usage::<A>::TYPE_GAUGE, 1000)? {
                for k in gauge_keys {
                    self.buffer.remove(&k);
                }
            } else {
                overall = false;
            }
        }
        if self.buffer.is_empty() {
            self.flushed_at = Instant::now();
        }
        Ok(overall)
    }

    #[must_use]
    pub fn count(&self) -> usize {
        self.buffer.len()
    }

    #[must_use]
    pub fn elapsed_seconds(&self) -> f64 {
        self.flushed_at.elapsed().as_secs_f64()
    }
}
