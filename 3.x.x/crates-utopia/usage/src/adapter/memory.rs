//! In-memory usage adapter for tests.

use std::sync::{Arc, Mutex};

use serde_json::{json, Map, Value};

use crate::adapter::sql::SqlAdapter;
use crate::adapter::Adapter;
use crate::error::Result;
use crate::metric::Metric;
use crate::usage::{TYPE_EVENT, TYPE_GAUGE};
use crate::usage_query::UsageQuery;

#[derive(Debug, Clone, Default)]
pub struct Memory {
    events: Arc<Mutex<Vec<Map<String, Value>>>>,
    gauges: Arc<Mutex<Vec<Map<String, Value>>>>,
}

impl Memory {
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    fn store(&self, type_: &str) -> Arc<Mutex<Vec<Map<String, Value>>>> {
        if type_ == TYPE_GAUGE {
            self.gauges.clone()
        } else {
            self.events.clone()
        }
    }

    fn matches(row: &Map<String, Value>, tenant: &str, queries: &[UsageQuery]) -> bool {
        if row.get("tenant").and_then(Value::as_str) != Some(tenant) && !tenant.is_empty() {
            return false;
        }
        for q in queries {
            match q.get_method() {
                "equal" => {
                    let key = q.get_attribute();
                    let actual = row.get(key).cloned().unwrap_or(Value::Null);
                    let ok = q.get_values().iter().any(|v| v.to_json() == actual);
                    if !ok {
                        return false;
                    }
                }
                "limit" | "offset" | "orderAsc" | "orderDesc" | "groupByInterval" | "groupBy"
                | "aggregate" => {}
                _ => {}
            }
        }
        true
    }
}

impl Adapter for Memory {
    fn get_name(&self) -> &'static str {
        "Memory"
    }

    fn health_check(&self) -> Map<String, Value> {
        let mut m = Map::new();
        m.insert("healthy".into(), json!(true));
        m
    }

    fn setup(&mut self) -> Result<()> {
        Ok(())
    }

    fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        type_: &str,
        _batch_size: i64,
    ) -> Result<bool> {
        let store = self.store(type_);
        let mut guard = store.lock().unwrap_or_else(|e| e.into_inner());
        for mut metric in metrics {
            let tags = metric
                .get("tags")
                .and_then(Value::as_object)
                .cloned()
                .unwrap_or_default();
            if let Ok(cols) = Metric::extract_columns(&tags, type_) {
                for (k, v) in cols {
                    metric.entry(k).or_insert(v);
                }
            }
            guard.push(metric);
        }
        Ok(true)
    }

    fn get_time_series(
        &self,
        tenant: &str,
        metrics: &[String],
        _interval: &str,
        _start_date: &str,
        _end_date: &str,
        queries: &[UsageQuery],
        _zero_fill: bool,
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        let mut out = Map::new();
        for metric in metrics {
            let total = self.get_total(tenant, metric, queries, type_)?;
            let mut entry = Map::new();
            entry.insert("total".into(), json!(total as f64));
            entry.insert("data".into(), json!([]));
            out.insert(metric.clone(), Value::Object(entry));
        }
        Ok(out)
    }

    fn get_total(
        &self,
        tenant: &str,
        metric: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<i64> {
        let mut q = queries.to_vec();
        q.push(UsageQuery::new("equal", "metric", vec![metric.into()]));
        self.sum(tenant, &q, "value", type_.unwrap_or(TYPE_EVENT))
    }

    fn get_total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        let mut out = Map::new();
        for m in metrics {
            out.insert(m.clone(), json!(self.get_total(tenant, m, queries, type_)?));
        }
        Ok(out)
    }

    fn purge(&mut self, tenant: &str, queries: &[UsageQuery], type_: Option<&str>) -> Result<bool> {
        let types: Vec<&str> = match type_ {
            Some(t) => vec![t],
            None => vec![TYPE_EVENT, TYPE_GAUGE],
        };
        for t in types {
            let store = self.store(t);
            let mut guard = store.lock().unwrap_or_else(|e| e.into_inner());
            guard.retain(|row| !Self::matches(row, tenant, queries));
        }
        Ok(true)
    }

    fn find(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        let types: Vec<&str> = match type_ {
            Some(t) => vec![t],
            None => vec![TYPE_EVENT, TYPE_GAUGE],
        };
        let mut out = Vec::new();
        for t in types {
            let store = self.store(t);
            let guard = store.lock().unwrap_or_else(|e| e.into_inner());
            for row in guard.iter() {
                if Self::matches(row, tenant, queries) {
                    let mut copy = row.clone();
                    copy.insert("type".into(), json!(t));
                    out.push(Metric::new(copy));
                }
            }
        }
        Ok(out)
    }

    fn count(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        type_: Option<&str>,
        max: Option<i64>,
    ) -> Result<i64> {
        let n = self.find(tenant, queries, type_)?.len() as i64;
        Ok(max.map(|m| n.min(m)).unwrap_or(n))
    }

    fn sum(
        &self,
        tenant: &str,
        queries: &[UsageQuery],
        attribute: &str,
        type_: &str,
    ) -> Result<i64> {
        let rows = self.find(tenant, queries, Some(type_))?;
        Ok(rows
            .iter()
            .map(|m| {
                m.get_attribute(attribute)
                    .and_then(Value::as_i64)
                    .unwrap_or(0)
            })
            .sum())
    }

    fn find_daily(&self, tenant: &str, queries: &[UsageQuery]) -> Result<Vec<Metric>> {
        self.find(tenant, queries, Some(TYPE_EVENT))
    }

    fn sum_daily(&self, tenant: &str, queries: &[UsageQuery], attribute: &str) -> Result<i64> {
        self.sum(tenant, queries, attribute, TYPE_EVENT)
    }

    fn sum_daily_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[UsageQuery],
    ) -> Result<Map<String, Value>> {
        self.get_total_batch(tenant, metrics, queries, Some(TYPE_EVENT))
    }
}

impl SqlAdapter for Memory {}
