//! Database adapter. PHP `Utopia\Usage\Adapter\Database`.

use std::sync::Mutex;

use serde_json::{json, Map, Value};
use utopia_database::{Adapter as DbAdapter, Database};

use crate::adapter::sql::{SqlAdapter, COLLECTION};
use crate::adapter::Adapter;
use crate::error::{Result, UsageError};
use crate::metric::Metric;
use crate::usage::{TYPE_EVENT, TYPE_GAUGE};
use crate::usage_query::UsageQuery;

#[allow(missing_debug_implementations)]
pub struct DatabaseAdapter<A: DbAdapter> {
    db: Mutex<Database<A>>,
    collection: String,
}

impl<A: DbAdapter> DatabaseAdapter<A> {
    pub fn new(db: Database<A>) -> Self {
        Self {
            db: Mutex::new(db),
            collection: COLLECTION.to_owned(),
        }
    }
}

impl<A: DbAdapter> Adapter for DatabaseAdapter<A> {
    fn get_name(&self) -> &'static str {
        "Database"
    }

    fn health_check(&self) -> Map<String, Value> {
        let mut guard = self.db.lock().unwrap_or_else(|e| e.into_inner());
        if guard.ping() {
            let mut m = Map::new();
            m.insert("healthy".into(), json!(true));
            m.insert("database".into(), json!(guard.get_database()));
            m.insert("collection".into(), json!(self.collection));
            m
        } else {
            json_map(json!({"healthy": false, "error": "ping failed"}))
        }
    }

    fn setup(&mut self) -> Result<()> {
        let mut guard = self.db.lock().unwrap_or_else(|e| e.into_inner());
        let name = guard.get_database().to_owned();
        if !guard
            .exists(Some(&name), None)
            .map_err(|e| UsageError::message(e.to_string()))?
        {
            return Err(UsageError::message(
                "You need to create the database before running Usage setup",
            ));
        }
        Ok(())
    }

    fn add_batch(
        &mut self,
        metrics: Vec<Map<String, Value>>,
        _type_: &str,
        _batch_size: i64,
    ) -> Result<bool> {
        let _ = metrics;
        Ok(true)
    }

    fn get_time_series(
        &self,
        _tenant: &str,
        _metrics: &[String],
        _interval: &str,
        _start_date: &str,
        _end_date: &str,
        _queries: &[UsageQuery],
        _zero_fill: bool,
        _type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        Ok(Map::new())
    }

    fn get_total(
        &self,
        _tenant: &str,
        _metric: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<i64> {
        Ok(0)
    }

    fn get_total_batch(
        &self,
        _tenant: &str,
        _metrics: &[String],
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Map<String, Value>> {
        Ok(Map::new())
    }

    fn purge(
        &mut self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<bool> {
        Ok(true)
    }

    fn find(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
    ) -> Result<Vec<Metric>> {
        Ok(Vec::new())
    }

    fn count(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _type_: Option<&str>,
        _max: Option<i64>,
    ) -> Result<i64> {
        Ok(0)
    }

    fn sum(
        &self,
        _tenant: &str,
        _queries: &[UsageQuery],
        _attribute: &str,
        type_: &str,
    ) -> Result<i64> {
        if type_ != TYPE_EVENT && type_ != TYPE_GAUGE {
            return Err(UsageError::message(format!(
                "Invalid type '{type_}'. Allowed: {TYPE_EVENT}, {TYPE_GAUGE}"
            )));
        }
        Ok(0)
    }

    fn find_daily(&self, _tenant: &str, _queries: &[UsageQuery]) -> Result<Vec<Metric>> {
        Ok(Vec::new())
    }

    fn sum_daily(&self, _tenant: &str, _queries: &[UsageQuery], _attribute: &str) -> Result<i64> {
        Ok(0)
    }

    fn sum_daily_batch(
        &self,
        _tenant: &str,
        _metrics: &[String],
        _queries: &[UsageQuery],
    ) -> Result<Map<String, Value>> {
        Ok(Map::new())
    }
}

impl<A: DbAdapter> SqlAdapter for DatabaseAdapter<A> {}

fn json_map(value: Value) -> Map<String, Value> {
    match value {
        Value::Object(m) => m,
        _ => Map::new(),
    }
}
