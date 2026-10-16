//! Database adapter. PHP `Utopia\Audit\Adapter\Database`.

use std::sync::Mutex;

use chrono::{DateTime, Utc};
use serde_json::{json, Map, Value};
use utopia_database::datetime::DateTime as DbDateTime;
use utopia_database::document::Document;
use utopia_database::query::Query as DbQuery;
use utopia_database::{Adapter as DbAdapter, Database};

use crate::adapter::sql::{SqlAdapter, COLLECTION};
use crate::adapter::Adapter;
use crate::error::{AuditError, Result};
use crate::log::Log;
use crate::query::Query;

/// Stores audit logs in a Utopia Database collection.
#[allow(missing_debug_implementations)]
pub struct DatabaseAdapter<A: DbAdapter> {
    db: Mutex<Database<A>>,
}

impl<A: DbAdapter> DatabaseAdapter<A> {
    /// PHP `__construct(Database $db)`.
    pub fn new(db: Database<A>) -> Self {
        Self { db: Mutex::new(db) }
    }

    fn collection(&self) -> &'static str {
        COLLECTION
    }

    fn to_db_queries(queries: &[Query]) -> Result<Vec<DbQuery>> {
        queries
            .iter()
            .map(|q| {
                let Value::Object(map) = q.to_array() else {
                    return Err(AuditError::message("Invalid query. Must be an array"));
                };
                DbQuery::parse_query(&map).map_err(|e| AuditError::message(e.to_string()))
            })
            .collect()
    }

    fn document_to_log(document: &Document) -> Log {
        Log::new(document.get_array_copy_json(&[], &[]))
    }

    fn build_time_queries(
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
    ) -> Vec<Query> {
        match (after, before) {
            (Some(a), Some(b)) => vec![Query::between("time", format_db(a), format_db(b))],
            (Some(a), None) => vec![Query::greater_than("time", format_db(a))],
            (None, Some(b)) => vec![Query::less_than("time", format_db(b))],
            (None, None) => vec![],
        }
    }

    fn with_db<R>(&self, f: impl FnOnce(&mut Database<A>) -> Result<R>) -> Result<R> {
        let mut guard = self.db.lock().unwrap_or_else(|e| e.into_inner());
        f(&mut guard)
    }
}

impl<A: DbAdapter> Adapter for DatabaseAdapter<A> {
    fn get_name(&self) -> &'static str {
        "Database"
    }

    fn setup(&mut self) -> Result<()> {
        let attributes = self.get_attribute_documents()?;
        let indexes = self.get_index_documents()?;
        self.with_db(|db| {
            let name = db.get_database().to_owned();
            if !db
                .exists(Some(&name), None)
                .map_err(|e| AuditError::message(e.to_string()))?
            {
                return Err(AuditError::message(
                    "You need to create the database before running Audit setup",
                ));
            }
            match db.create_collection(self.collection(), attributes, indexes, None, true) {
                Ok(_) => Ok(()),
                Err(e) if e.to_string().to_lowercase().contains("duplicate") => Ok(()),
                Err(e) => Err(AuditError::message(e.to_string())),
            }
        })
    }

    fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
        self.with_db(|db| {
            db.skip_authorization(|db| {
                let document = db
                    .get_document(self.collection(), id, &[], false)
                    .map_err(|e| AuditError::message(e.to_string()))?;
                if document.is_empty() {
                    return Ok(None);
                }
                Ok(Some(Self::document_to_log(&document)))
            })
        })
    }

    fn create(&mut self, mut log: Map<String, Value>) -> Result<Log> {
        if !log.contains_key("time") {
            log.insert("time".into(), json!(DbDateTime::now()));
        }
        let document =
            Document::try_from_json_object(log).map_err(|e| AuditError::message(e.to_string()))?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                let created = db
                    .create_document(self.collection(), document)
                    .map_err(|e| AuditError::message(e.to_string()))?;
                Ok(Self::document_to_log(&created))
            })
        })
    }

    fn create_batch(&mut self, logs: Vec<Map<String, Value>>) -> Result<bool> {
        let documents: Result<Vec<Document>> = logs
            .into_iter()
            .map(|mut log| {
                if !log.contains_key("time") {
                    log.insert("time".into(), json!(DbDateTime::now()));
                }
                Document::try_from_json_object(log).map_err(|e| AuditError::message(e.to_string()))
            })
            .collect();
        let documents = documents?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                db.create_documents(self.collection(), documents, 100)
                    .map_err(|e| AuditError::message(e.to_string()))?;
                Ok(true)
            })
        })
    }

    fn get_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![Query::equal("userId", user_id)];
        q.extend(Self::build_time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("")
        } else {
            Query::order_desc("")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_user(
        &self,
        user_id: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![Query::equal("userId", user_id)];
        q.extend(Self::build_time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![Query::equal("resource", resource)];
        q.extend(Self::build_time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("")
        } else {
            Query::order_desc("")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_resource(
        &self,
        resource: &str,
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![Query::equal("resource", resource)];
        q.extend(Self::build_time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![
            Query::equal("userId", user_id),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(Self::build_time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("")
        } else {
            Query::order_desc("")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![
            Query::equal("userId", user_id),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(Self::build_time_queries(after, before));
        self.count(&q, max)
    }

    fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        limit: i64,
        offset: i64,
        ascending: bool,
    ) -> Result<Vec<Log>> {
        let mut q = vec![
            Query::equal("resource", resource),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(Self::build_time_queries(after, before));
        q.push(if ascending {
            Query::order_asc("")
        } else {
            Query::order_desc("")
        });
        q.push(Query::limit(limit));
        q.push(Query::offset(offset));
        self.find(&q)
    }

    fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[String],
        after: Option<DateTime<Utc>>,
        before: Option<DateTime<Utc>>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut q = vec![
            Query::equal("resource", resource),
            Query::equal("event", events.to_vec()),
        ];
        q.extend(Self::build_time_queries(after, before));
        self.count(&q, max)
    }

    fn cleanup(&mut self, datetime: DateTime<Utc>) -> Result<bool> {
        let queries = vec![Query::less_than("time", format_db(datetime))];
        let db_queries = Self::to_db_queries(&queries)?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                db.delete_documents(self.collection(), &db_queries)
                    .map_err(|e| AuditError::message(e.to_string()))?;
                Ok(true)
            })
        })
    }

    fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        let db_queries = Self::to_db_queries(queries)?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                let documents = db
                    .find(self.collection(), &db_queries, "read")
                    .map_err(|e| AuditError::message(e.to_string()))?;
                Ok(documents.iter().map(Self::document_to_log).collect())
            })
        })
    }

    fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let filtered: Vec<Query> = queries
            .iter()
            .filter(|q| {
                !matches!(
                    q.get_method(),
                    Query::TYPE_LIMIT
                        | Query::TYPE_OFFSET
                        | Query::TYPE_CURSOR_AFTER
                        | Query::TYPE_CURSOR_BEFORE
                        | Query::TYPE_ORDER_ASC
                        | Query::TYPE_ORDER_DESC
                        | Query::TYPE_ORDER_RANDOM
                        | Query::TYPE_SELECT
                )
            })
            .cloned()
            .collect();
        let db_queries = Self::to_db_queries(&filtered)?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                db.count(self.collection(), &db_queries, max)
                    .map_err(|e| AuditError::message(e.to_string()))
            })
        })
    }

    fn ping(&self) -> bool {
        self.with_db(|db| Ok(db.ping())).unwrap_or(false)
    }
}

impl<A: DbAdapter> SqlAdapter for DatabaseAdapter<A> {
    fn get_column_definition(&self, id: &str) -> Result<String> {
        let attr = self
            .get_attribute(id)
            .ok_or_else(|| AuditError::message(format!("Attribute {id} not found")))?;
        let type_ = attr.get("type").and_then(Value::as_str).unwrap_or("string");
        Ok(format!("{id} {type_}"))
    }
}

fn format_db(dt: DateTime<Utc>) -> String {
    dt.format("%Y-%m-%d %H:%M:%S%.3f").to_string()
}
