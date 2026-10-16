use serde_json::{json, Map, Value};
use utopia_usage::{Accumulator, Adapter, Metric, Usage, UsageQuery};

struct RecordingAdapter {
    pub batches: Vec<(Vec<Map<String, Value>>, String)>,
    pub succeed: bool,
}

impl RecordingAdapter {
    fn new() -> Self {
        Self {
            batches: Vec::new(),
            succeed: true,
        }
    }
}

impl Adapter for RecordingAdapter {
    fn get_name(&self) -> &'static str {
        "recording"
    }
    fn health_check(&self) -> Map<String, Value> {
        json!({"healthy": true}).as_object().cloned().unwrap()
    }
    fn setup(&mut self) -> utopia_usage::Result<()> {
        Ok(())
    }
    fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        type_: &str,
        _batch_size: i64,
    ) -> utopia_usage::Result<bool> {
        if self.succeed {
            self.batches.push((metrics, type_.to_owned()));
        }
        Ok(self.succeed)
    }
    fn get_time_series(
        &self,
        _t: &str,
        _m: &[String],
        _i: &str,
        _s: &str,
        _e: &str,
        _q: &[UsageQuery],
        _z: bool,
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Map<String, Value>> {
        Ok(Map::new())
    }
    fn get_total(
        &self,
        _t: &str,
        _m: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<i64> {
        Ok(0)
    }
    fn get_total_batch(
        &self,
        _t: &str,
        _m: &[String],
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Map<String, Value>> {
        Ok(Map::new())
    }
    fn purge(
        &mut self,
        _t: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<bool> {
        Ok(true)
    }
    fn find(
        &self,
        _t: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Vec<Metric>> {
        Ok(Vec::new())
    }
    fn count(
        &self,
        _t: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
        _max: Option<i64>,
    ) -> utopia_usage::Result<i64> {
        Ok(0)
    }
    fn sum(&self, _t: &str, _q: &[UsageQuery], _a: &str, _ty: &str) -> utopia_usage::Result<i64> {
        Ok(0)
    }
    fn find_daily(&self, _t: &str, _q: &[UsageQuery]) -> utopia_usage::Result<Vec<Metric>> {
        Ok(Vec::new())
    }
    fn sum_daily(&self, _t: &str, _q: &[UsageQuery], _a: &str) -> utopia_usage::Result<i64> {
        Ok(0)
    }
    fn sum_daily_batch(
        &self,
        _t: &str,
        _m: &[String],
        _q: &[UsageQuery],
    ) -> utopia_usage::Result<Map<String, Value>> {
        Ok(Map::new())
    }
}

#[test]
fn events_sum_by_key() {
    let adapter = RecordingAdapter::new();
    let mut acc = Accumulator::new(Usage::new(adapter));
    acc.collect(
        "t1",
        "requests",
        10,
        Usage::<RecordingAdapter>::TYPE_EVENT,
        Map::new(),
        None,
        false,
    )
    .unwrap();
    acc.collect(
        "t1",
        "requests",
        20,
        Usage::<RecordingAdapter>::TYPE_EVENT,
        Map::new(),
        None,
        false,
    )
    .unwrap();
    acc.collect(
        "t1",
        "requests",
        30,
        Usage::<RecordingAdapter>::TYPE_EVENT,
        Map::new(),
        None,
        false,
    )
    .unwrap();
    assert_eq!(acc.count(), 1);
}

#[test]
fn tags_partition_entries() {
    let adapter = RecordingAdapter::new();
    let mut acc = Accumulator::new(Usage::new(adapter));
    let mut us = Map::new();
    us.insert("region".into(), json!("us"));
    let mut eu = Map::new();
    eu.insert("region".into(), json!("eu"));
    acc.collect("t1", "requests", 10, "event", us, None, false)
        .unwrap();
    acc.collect("t1", "requests", 20, "event", eu, None, false)
        .unwrap();
    assert_eq!(acc.count(), 2);
}

#[test]
fn empty_tenant_rejected() {
    let adapter = RecordingAdapter::new();
    let mut acc = Accumulator::new(Usage::new(adapter));
    let err = acc
        .collect("", "requests", 1, "event", Map::new(), None, false)
        .unwrap_err();
    assert!(err.to_string().contains("Tenant cannot be empty"));
}

#[test]
fn negative_rejected() {
    let adapter = RecordingAdapter::new();
    let mut acc = Accumulator::new(Usage::new(adapter));
    let err = acc
        .collect("t", "requests", -1, "event", Map::new(), None, false)
        .unwrap_err();
    assert!(err.to_string().contains("Value cannot be negative"));
}
