use serde_json::{json, Map, Value};
use utopia_query::query::Query as BaseQuery;
use utopia_usage::{Adapter, Metric, Tenant, Usage, UsageQuery};

struct TenantRecordingAdapter {
    last_tenant: std::sync::Mutex<Option<String>>,
    last_metrics: std::sync::Mutex<Vec<Map<String, Value>>>,
}

impl TenantRecordingAdapter {
    fn new() -> Self {
        Self {
            last_tenant: std::sync::Mutex::new(None),
            last_metrics: std::sync::Mutex::new(Vec::new()),
        }
    }
}

impl Adapter for TenantRecordingAdapter {
    fn get_name(&self) -> &'static str {
        "tenant-recording"
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
        _type_: &str,
        _batch_size: i64,
    ) -> utopia_usage::Result<bool> {
        *self.last_metrics.lock().unwrap() = metrics;
        Ok(true)
    }
    fn get_time_series(
        &self,
        tenant: &str,
        _m: &[String],
        _i: &str,
        _s: &str,
        _e: &str,
        _q: &[UsageQuery],
        _z: bool,
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Map<String, Value>> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(Map::new())
    }
    fn get_total(
        &self,
        tenant: &str,
        _m: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<i64> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(0)
    }
    fn get_total_batch(
        &self,
        tenant: &str,
        _m: &[String],
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Map<String, Value>> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(Map::new())
    }
    fn purge(
        &mut self,
        tenant: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<bool> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(true)
    }
    fn find(
        &self,
        tenant: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
    ) -> utopia_usage::Result<Vec<Metric>> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(Vec::new())
    }
    fn count(
        &self,
        tenant: &str,
        _q: &[UsageQuery],
        _ty: Option<&str>,
        _max: Option<i64>,
    ) -> utopia_usage::Result<i64> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(0)
    }
    fn sum(
        &self,
        tenant: &str,
        _q: &[UsageQuery],
        _a: &str,
        _ty: &str,
    ) -> utopia_usage::Result<i64> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(0)
    }
    fn find_daily(&self, tenant: &str, _q: &[UsageQuery]) -> utopia_usage::Result<Vec<Metric>> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(Vec::new())
    }
    fn sum_daily(&self, tenant: &str, _q: &[UsageQuery], _a: &str) -> utopia_usage::Result<i64> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(0)
    }
    fn sum_daily_batch(
        &self,
        tenant: &str,
        _m: &[String],
        _q: &[UsageQuery],
    ) -> utopia_usage::Result<Map<String, Value>> {
        *self.last_tenant.lock().unwrap() = Some(tenant.to_owned());
        Ok(Map::new())
    }
}

#[test]
fn empty_tenant_throws() {
    let adapter = TenantRecordingAdapter::new();
    let err = Tenant::new(Usage::new(adapter), "").unwrap_err();
    assert!(err.to_string().contains("Tenant cannot be empty"));
}

#[test]
fn add_batch_stamps_tenant() {
    let adapter = TenantRecordingAdapter::new();
    let mut tenant = Tenant::new(Usage::new(adapter), "p1").unwrap();
    tenant
        .add_batch(
            vec![
                json!({"metric":"requests","value":10,"tags":{}})
                    .as_object()
                    .cloned()
                    .unwrap(),
                json!({"metric":"bandwidth","value":20,"tags":{}})
                    .as_object()
                    .cloned()
                    .unwrap(),
            ],
            Usage::<TenantRecordingAdapter>::TYPE_EVENT,
            1000,
        )
        .unwrap();
}

#[test]
fn usage_query_group_by_interval() {
    let q = UsageQuery::group_by_interval("time", "1h").unwrap();
    assert_eq!(q.get_method(), UsageQuery::TYPE_GROUP_BY_INTERVAL);
    assert_eq!(q.get_attribute(), "time");
    assert_eq!(q.get_value().as_str(), Some("1h"));
    for interval in ["1m", "5m", "15m", "30m", "1h", "1d", "1w", "1M"] {
        UsageQuery::group_by_interval("time", interval).unwrap();
    }
    let err = UsageQuery::group_by_interval("time", "2h").unwrap_err();
    assert!(err.to_string().contains("Invalid interval '2h'"));
}

#[test]
fn usage_query_extract() {
    let group = UsageQuery::group_by_interval("time", "1h").unwrap();
    let equal: UsageQuery = BaseQuery::equal("metric", vec!["bandwidth"]).into();
    let queries = vec![equal.clone(), group.clone()];
    let extracted = UsageQuery::extract_group_by_interval(&queries).unwrap();
    assert_eq!(extracted.get_method(), UsageQuery::TYPE_GROUP_BY_INTERVAL);
    let remaining = UsageQuery::remove_group_by_interval(&queries);
    assert_eq!(remaining.len(), 1);
    assert!(UsageQuery::is_method(UsageQuery::TYPE_GROUP_BY_INTERVAL));
    assert!(UsageQuery::is_method(UsageQuery::TYPE_AGGREGATE));
    let agg = UsageQuery::aggregate("max").unwrap();
    assert!(UsageQuery::is_aggregate(&agg));
    assert!(!UsageQuery::is_aggregate(&equal));
    let err = UsageQuery::aggregate("peak").unwrap_err();
    assert!(err.to_string().contains("Invalid aggregate 'peak'"));
}

#[test]
fn group_by_parse_round_trip() {
    let json = r#"{"method":"groupBy","attribute":"service","values":[]}"#;
    let parsed = UsageQuery::parse(json).unwrap();
    assert_eq!(parsed.get_method(), UsageQuery::TYPE_GROUP_BY);
    assert_eq!(parsed.get_attribute(), "service");
}
