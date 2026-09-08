use serde_json::{json, Map, Value};

use crate::adapter::{remaining_from, Adapter, AdapterState};
use crate::database::{
    Database as DatabaseTrait, Document, Query, INDEX_KEY, INDEX_UNIQUE, LENGTH_KEY, VAR_DATETIME,
    VAR_INTEGER, VAR_STRING,
};
use crate::error::AbuseError;
use crate::logs::Logs;
use crate::time_util::{align_timestamp, format_datetime, unix_now};

use super::COLLECTION;

/// PHP `TimeLimit\Database::ATTRIBUTES`.
pub fn attributes() -> Vec<Document> {
    vec![
        document_from(json!({
            "$id": "key",
            "type": VAR_STRING,
            "size": LENGTH_KEY,
            "required": true,
            "signed": true,
            "array": false,
            "filters": [],
        })),
        document_from(json!({
            "$id": "time",
            "type": VAR_DATETIME,
            "size": 0,
            "required": true,
            "signed": false,
            "array": false,
            "filters": ["datetime"],
        })),
        document_from(json!({
            "$id": "count",
            "type": VAR_INTEGER,
            "size": 11,
            "required": true,
            "signed": false,
            "array": false,
            "filters": [],
        })),
    ]
}

/// PHP `TimeLimit\Database::INDEXES`.
pub fn indexes() -> Vec<Document> {
    vec![
        document_from(json!({
            "$id": "unique1",
            "type": INDEX_UNIQUE,
            "attributes": ["key", "time"],
            "lengths": [],
            "orders": [],
        })),
        document_from(json!({
            "$id": "index2",
            "type": INDEX_KEY,
            "attributes": ["time"],
            "lengths": [],
            "orders": [],
        })),
    ]
}

fn document_from(value: Value) -> Document {
    let map = value.as_object().cloned().unwrap_or_else(Map::new);
    Document::new(map)
}

/// PHP `Utopia\Abuse\Adapters\TimeLimit\Database`.
#[derive(Debug, Clone)]
pub struct Database<D: DatabaseTrait> {
    state: AdapterState,
    limit: i64,
    timestamp: i64,
    count: Option<i64>,
    db: D,
}

impl<D: DatabaseTrait> Database<D> {
    /// PHP `new Database($key, $limit, $seconds, $db)`.
    #[must_use]
    pub fn new(key: impl Into<String>, limit: i64, seconds: i64, db: D) -> Self {
        let now = unix_now();
        Self {
            state: AdapterState::new(key),
            limit,
            timestamp: align_timestamp(now, seconds),
            count: None,
            db,
        }
    }

    /// PHP `COLLECTION`.
    pub const COLLECTION: &'static str = COLLECTION;

    /// PHP `setup()`.
    ///
    /// # Errors
    ///
    /// Missing database or collection-create failures (duplicate is ignored).
    pub fn setup(&self) -> Result<(), AbuseError> {
        if !self.db.exists(&self.db.get_database())? {
            return Err(AbuseError::DatabaseNotCreated);
        }
        match self
            .db
            .create_collection(COLLECTION, attributes(), indexes())
        {
            Ok(()) | Err(crate::database::DatabaseError::Duplicate) => Ok(()),
            Err(err) => Err(err.into()),
        }
    }

    fn to_datetime(timestamp: i64) -> String {
        format_datetime(timestamp)
    }

    fn count(&mut self, key: &str, timestamp: i64) -> Result<i64, AbuseError> {
        if self.limit == 0 {
            return Ok(0);
        }
        if let Some(count) = self.count {
            return Ok(count);
        }
        let timestamp = Self::to_datetime(timestamp);
        let result = self.db.skip_authorization(|| {
            self.db
                .find(
                    COLLECTION,
                    &[
                        Query::equal("key", vec![Value::String(key.to_owned())]),
                        Query::equal("time", vec![Value::String(timestamp.clone())]),
                    ],
                )
                .map_err(AbuseError::from)
        })?;
        let mut count = 0;
        if result.len() == 1 {
            let attr = result[0].get_attribute("count", Value::from(0));
            if let Some(number) = attr
                .as_i64()
                .or_else(|| attr.as_u64().map(|n| n as i64))
                .or_else(|| attr.as_f64().map(|n| n as i64))
            {
                count = number;
            } else if let Some(text) = attr.as_str() {
                count = text.parse().unwrap_or(0);
            }
        }
        self.count = Some(count);
        Ok(count)
    }

    fn hit(&mut self, key: &str, timestamp: i64) -> Result<(), AbuseError> {
        if self.limit == 0 {
            return Ok(());
        }
        let timestamp = Self::to_datetime(timestamp);
        self.db.skip_authorization(|| {
            let data = self.db.find_one(
                COLLECTION,
                &[
                    Query::equal("key", vec![Value::String(key.to_owned())]),
                    Query::equal("time", vec![Value::String(timestamp.clone())]),
                ],
            )?;
            if data.is_empty() {
                let mut created = Map::new();
                created.insert("$permissions".into(), json!([]));
                created.insert("key".into(), Value::String(key.to_owned()));
                created.insert("time".into(), Value::String(timestamp.clone()));
                created.insert("count".into(), Value::from(1));
                created.insert("$collection".into(), Value::String(COLLECTION.to_owned()));
                match self.db.create_document(COLLECTION, Document::new(created)) {
                    Ok(_) => Ok(()),
                    Err(crate::database::DatabaseError::Duplicate) => {
                        let data = self.db.find_one(
                            COLLECTION,
                            &[
                                Query::equal("key", vec![Value::String(key.to_owned())]),
                                Query::equal("time", vec![Value::String(timestamp.clone())]),
                            ],
                        )?;
                        if data.is_empty() {
                            return Err(AbuseError::DocumentNotFound);
                        }
                        let attr = data.get_attribute("count", Value::from(0));
                        if let Some(number) =
                            attr.as_i64().or_else(|| attr.as_u64().map(|n| n as i64))
                        {
                            self.count = Some(number);
                        }
                        self.db
                            .increase_document_attribute(COLLECTION, data.get_id(), "count")?;
                        Ok(())
                    }
                    Err(err) => Err(err.into()),
                }
            } else {
                self.db
                    .increase_document_attribute(COLLECTION, data.get_id(), "count")?;
                Ok(())
            }
        })?;
        self.count = Some(self.count.unwrap_or(0) + 1);
        Ok(())
    }

    fn set(&mut self, key: &str, timestamp: i64, value: i64) -> Result<(), AbuseError> {
        let timestamp = Self::to_datetime(timestamp);
        self.db.skip_authorization(|| {
            let data = self.db.find_one(
                COLLECTION,
                &[
                    Query::equal("key", vec![Value::String(key.to_owned())]),
                    Query::equal("time", vec![Value::String(timestamp.clone())]),
                ],
            )?;
            if data.is_empty() {
                let mut created = Map::new();
                created.insert("$permissions".into(), json!([]));
                created.insert("key".into(), Value::String(key.to_owned()));
                created.insert("time".into(), Value::String(timestamp.clone()));
                created.insert("count".into(), Value::from(value));
                created.insert("$collection".into(), Value::String(COLLECTION.to_owned()));
                match self.db.create_document(COLLECTION, Document::new(created)) {
                    Ok(_) => Ok(()),
                    Err(crate::database::DatabaseError::Duplicate) => {
                        let data = self.db.find_one(
                            COLLECTION,
                            &[
                                Query::equal("key", vec![Value::String(key.to_owned())]),
                                Query::equal("time", vec![Value::String(timestamp.clone())]),
                            ],
                        )?;
                        if data.is_empty() {
                            return Err(AbuseError::DocumentRace);
                        }
                        let mut patch = Map::new();
                        patch.insert("count".into(), Value::from(value));
                        self.db
                            .update_document(COLLECTION, data.get_id(), Document::new(patch))?;
                        Ok(())
                    }
                    Err(err) => Err(err.into()),
                }
            } else {
                let mut patch = Map::new();
                patch.insert("count".into(), Value::from(value));
                self.db
                    .update_document(COLLECTION, data.get_id(), Document::new(patch))?;
                Ok(())
            }
        })?;
        self.count = Some(value);
        Ok(())
    }

    /// PHP `remaining()`.
    ///
    /// # Errors
    ///
    /// Database failures.
    pub fn remaining(&mut self) -> Result<i64, AbuseError> {
        let key = self.state.parse_key();
        let count = self.count(&key, self.timestamp)?;
        Ok(remaining_from(self.limit, count))
    }

    /// PHP `limit()`.
    #[must_use]
    pub fn limit(&self) -> i64 {
        self.limit
    }

    /// PHP `time()`.
    #[must_use]
    pub fn time(&self) -> i64 {
        self.timestamp
    }
}

impl<D: DatabaseTrait> Adapter for Database<D> {
    fn check(&mut self) -> Result<bool, AbuseError> {
        if self.limit == 0 {
            return Ok(false);
        }
        let key = self.state.parse_key();
        let timestamp = self.timestamp;
        if self.limit > self.count(&key, timestamp)? {
            self.hit(&key, timestamp)?;
            return Ok(false);
        }
        Ok(true)
    }

    fn set_param(&mut self, key: &str, value: &str) -> &mut Self {
        self.state.set_param(key, value);
        self
    }

    fn parse_key(&mut self) -> String {
        self.state.parse_key()
    }

    fn get_logs(&mut self, offset: Option<i64>, limit: Option<i64>) -> Result<Logs, AbuseError> {
        self.db.skip_authorization(|| {
            let mut queries = vec![Query::order_desc("")];
            if let Some(offset) = offset {
                queries.push(Query::offset(offset));
            }
            if let Some(limit) = limit {
                queries.push(Query::limit(limit));
            }
            Ok(Logs::Documents(self.db.find(COLLECTION, &queries)?))
        })
    }

    fn cleanup(&mut self, timestamp: i64) -> Result<bool, AbuseError> {
        let timestamp = Self::to_datetime(timestamp);
        self.db.skip_authorization(|| {
            loop {
                let documents = self.db.find(
                    COLLECTION,
                    &[Query::less_than("time", Value::String(timestamp.clone()))],
                )?;
                if documents.is_empty() {
                    break;
                }
                for document in documents {
                    self.db.delete_document(COLLECTION, document.get_id())?;
                }
            }
            Ok(())
        })?;
        Ok(true)
    }

    fn reset(&mut self) -> Result<(), AbuseError> {
        let key = self.state.parse_key();
        self.set(&key, self.timestamp, 0)
    }
}
