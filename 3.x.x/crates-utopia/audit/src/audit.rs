//! Audit log manager. PHP `Utopia\Audit\Audit`.

use chrono::{DateTime, Utc};
use serde_json::{json, Map, Value};

use crate::adapter::Adapter;
use crate::error::Result;
use crate::log::Log;
use crate::query::Query;

/// Manages audit logs through a pluggable [`Adapter`].
#[allow(missing_debug_implementations)]
pub struct Audit<A: Adapter> {
    adapter: A,
}

impl<A: Adapter> Audit<A> {
    /// PHP `__construct(Adapter $adapter)`.
    pub fn new(adapter: A) -> Self {
        Self { adapter }
    }

    pub fn get_adapter(&self) -> &A {
        &self.adapter
    }

    pub fn get_adapter_mut(&mut self) -> &mut A {
        &mut self.adapter
    }

    pub fn setup(&mut self) -> Result<()> {
        self.adapter.setup()
    }

    /// PHP `log(?string $userId, string $event, string $resource, string $userAgent, string $ip, array $data = [])`.
    pub fn log(
        &mut self,
        user_id: Option<&str>,
        event: impl Into<String>,
        resource: impl Into<String>,
        user_agent: impl Into<String>,
        ip: impl Into<String>,
        data: Map<String, Value>,
    ) -> Result<Log> {
        let mut log = Map::new();
        log.insert("userId".into(), json!(user_id));
        log.insert("event".into(), json!(event.into()));
        log.insert("resource".into(), json!(resource.into()));
        log.insert("userAgent".into(), json!(user_agent.into()));
        log.insert("ip".into(), json!(ip.into()));
        log.insert("data".into(), Value::Object(data));
        self.adapter.create(log)
    }

    pub fn log_batch(&mut self, events: Vec<Map<String, Value>>) -> Result<bool> {
        self.adapter.create_batch(events)
    }

    pub fn get_log_by_id(&self, id: &str) -> Result<Option<Log>> {
        self.adapter.get_by_id(id)
    }

    pub fn get_logs_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        self.adapter
            .get_by_user(user_id, after, before, limit, offset, ascending)
    }

    pub fn count_logs_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter.count_by_user(user_id, after, before, max)
    }

    pub fn get_logs_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        self.adapter
            .get_by_resource(resource, after, before, limit, offset, ascending)
    }

    pub fn count_logs_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter.count_by_resource(resource, after, before, max)
    }

    pub fn get_logs_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        self.adapter
            .get_by_user_and_events(user_id, events, after, before, limit, offset, ascending)
    }

    pub fn count_logs_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter
            .count_by_user_and_events(user_id, events, after, before, max)
    }

    pub fn get_logs_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        self.adapter
            .get_by_resource_and_events(resource, events, after, before, limit, offset, ascending)
    }

    pub fn count_logs_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        self.adapter
            .count_by_resource_and_events(resource, events, after, before, max)
    }

    pub fn cleanup(&mut self, datetime: DateTime<Utc>) -> Result<bool> {
        self.adapter.cleanup(datetime)
    }

    pub fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        self.adapter.find(queries)
    }

    pub fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        self.adapter.count(queries, max)
    }

    pub fn ping(&self) -> bool {
        self.adapter.ping()
    }
}
