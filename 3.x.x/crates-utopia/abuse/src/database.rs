use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::{Map, Value};
use thiserror::Error;
use utopia_cache::adapter::Memory as CacheMemory;
use utopia_cache::Cache;
use utopia_database::query::Query as DbQuery;
use utopia_database::{AttrValue, Database as UtopiaDatabase, Document as DbDocument, Memory};

use crate::error::AbuseError;

/// PHP `Utopia\Database\Database::INDEX_KEY`.
pub use utopia_database::INDEX_KEY;
/// PHP `Utopia\Database\Database::INDEX_UNIQUE`.
pub use utopia_database::INDEX_UNIQUE;
/// PHP `Utopia\Database\Database::LENGTH_KEY`.
pub use utopia_database::LENGTH_KEY;
/// PHP `Utopia\Database\Database::VAR_DATETIME`.
pub use utopia_database::VAR_DATETIME;
/// PHP `Utopia\Database\Database::VAR_INTEGER`.
pub use utopia_database::VAR_INTEGER;
/// PHP `Utopia\Database\Database::VAR_STRING`.
pub use utopia_database::VAR_STRING;

/// Errors from a [`Database`] backend.
#[derive(Debug, Error, Clone, PartialEq, Eq)]
pub enum DatabaseError {
    /// Unique-index / duplicate-key collision (PHP `Duplicate`).
    #[error("Duplicate")]
    Duplicate,
    /// Other database failure.
    #[error("{0}")]
    Message(String),
}

/// Query filter matching the PHP `Utopia\Database\Query` helpers used by the adapter.
#[derive(Debug, Clone, PartialEq)]
pub enum Query {
    /// `Query::equal($attr, $values)`.
    Equal {
        /// Attribute name.
        attribute: String,
        /// Accepted values.
        values: Vec<Value>,
    },
    /// `Query::lessThan($attr, $value)`.
    LessThan {
        /// Attribute name.
        attribute: String,
        /// Exclusive upper bound.
        value: Value,
    },
    /// `Query::orderDesc($attr)` (`''` orders by document id).
    OrderDesc {
        /// Attribute name (empty string → `$id`).
        attribute: String,
    },
    /// `Query::offset($n)`.
    Offset(i64),
    /// `Query::limit($n)`.
    Limit(i64),
}

impl Query {
    /// PHP `Query::equal`.
    #[must_use]
    pub fn equal(attribute: impl Into<String>, values: Vec<Value>) -> Self {
        Self::Equal {
            attribute: attribute.into(),
            values,
        }
    }

    /// PHP `Query::lessThan`.
    #[must_use]
    pub fn less_than(attribute: impl Into<String>, value: Value) -> Self {
        Self::LessThan {
            attribute: attribute.into(),
            value,
        }
    }

    /// PHP `Query::orderDesc`.
    #[must_use]
    pub fn order_desc(attribute: impl Into<String>) -> Self {
        Self::OrderDesc {
            attribute: attribute.into(),
        }
    }

    /// PHP `Query::offset`.
    #[must_use]
    pub fn offset(offset: i64) -> Self {
        Self::Offset(offset)
    }

    /// PHP `Query::limit`.
    #[must_use]
    pub fn limit(limit: i64) -> Self {
        Self::Limit(limit)
    }
}

/// PHP `Utopia\Database\Document`.
#[derive(Debug, Clone, PartialEq)]
pub struct Document {
    id: String,
    data: Map<String, Value>,
    empty: bool,
}

impl Document {
    /// Empty document (`isEmpty() === true`), as returned by `findOne` misses.
    #[must_use]
    pub fn empty() -> Self {
        Self {
            id: String::new(),
            data: Map::new(),
            empty: true,
        }
    }

    /// Document from an attribute map. `$id` is read when present.
    #[must_use]
    pub fn new(data: Map<String, Value>) -> Self {
        let id = data
            .get("$id")
            .and_then(Value::as_str)
            .unwrap_or("")
            .to_owned();
        Self {
            id,
            data,
            empty: false,
        }
    }

    /// PHP `isEmpty()`.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.empty
    }

    /// PHP `getId()`.
    #[must_use]
    pub fn get_id(&self) -> &str {
        &self.id
    }

    /// Set `$id`.
    pub fn set_id(&mut self, id: impl Into<String>) {
        let id = id.into();
        self.data
            .insert("$id".to_owned(), Value::String(id.clone()));
        self.id = id;
        self.empty = false;
    }

    /// PHP `getAttribute($key, $default)`.
    #[must_use]
    pub fn get_attribute(&self, key: &str, default: Value) -> Value {
        self.data.get(key).cloned().unwrap_or(default)
    }

    /// Insert or replace an attribute.
    pub fn set_attribute(&mut self, key: impl Into<String>, value: Value) {
        self.data.insert(key.into(), value);
        self.empty = false;
    }

    /// PHP `toArray()`.
    #[must_use]
    pub fn to_array(&self) -> Map<String, Value> {
        self.data.clone()
    }

    /// Borrow attributes.
    #[must_use]
    pub fn data(&self) -> &Map<String, Value> {
        &self.data
    }
}

/// Neighboring `utopia-php/database` surface used by the time-limit Database adapter.
pub trait Database: Send + Sync {
    /// PHP `exists($name)`.
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn exists(&self, name: &str) -> Result<bool, DatabaseError>;

    /// PHP `getDatabase()`.
    fn get_database(&self) -> String;

    /// PHP `createCollection($name, $attributes, $indexes)`. Duplicate → [`DatabaseError::Duplicate`].
    ///
    /// # Errors
    ///
    /// Returns backend failures, including duplicate collection.
    fn create_collection(
        &self,
        name: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
    ) -> Result<(), DatabaseError>;

    /// PHP `find($collection, $queries)`.
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn find(&self, collection: &str, queries: &[Query]) -> Result<Vec<Document>, DatabaseError>;

    /// PHP `findOne($collection, $queries)` - empty document when nothing matches.
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn find_one(&self, collection: &str, queries: &[Query]) -> Result<Document, DatabaseError>;

    /// PHP `createDocument($collection, $document)`.
    ///
    /// # Errors
    ///
    /// Returns [`DatabaseError::Duplicate`] on unique-index collision.
    fn create_document(
        &self,
        collection: &str,
        document: Document,
    ) -> Result<Document, DatabaseError>;

    /// PHP `increaseDocumentAttribute($collection, $id, $attribute)` (step 1).
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
    ) -> Result<(), DatabaseError>;

    /// PHP `updateDocument($collection, $id, $document)` - merges provided attributes.
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn update_document(
        &self,
        collection: &str,
        id: &str,
        document: Document,
    ) -> Result<(), DatabaseError>;

    /// PHP `deleteDocument($collection, $id)`.
    ///
    /// # Errors
    ///
    /// Returns backend failures.
    fn delete_document(&self, collection: &str, id: &str) -> Result<(), DatabaseError>;

    /// PHP `getAuthorization()->skip(fn)`.
    ///
    /// # Errors
    ///
    /// Propagates the closure result.
    fn skip_authorization<F, T>(&self, func: F) -> Result<T, AbuseError>
    where
        F: FnOnce() -> Result<T, AbuseError>;
}

impl From<utopia_database::DatabaseError> for DatabaseError {
    fn from(err: utopia_database::DatabaseError) -> Self {
        match err {
            utopia_database::DatabaseError::Duplicate(_)
            | utopia_database::DatabaseError::Unique(_) => Self::Duplicate,
            other => Self::Message(other.to_string()),
        }
    }
}

/// In-memory [`Database`] backed by [`utopia_database::Memory`].
#[derive(Clone)]
pub struct MemoryDatabase {
    inner: Arc<Mutex<UtopiaDatabase<Memory>>>,
}

impl std::fmt::Debug for MemoryDatabase {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("MemoryDatabase")
            .field("database", &self.get_database())
            .finish()
    }
}

impl MemoryDatabase {
    /// Named database that does not exist until [`Self::create`].
    #[must_use]
    pub fn new(name: impl Into<String>) -> Self {
        let mut db = UtopiaDatabase::new(Memory::new(), Cache::new(CacheMemory::new()));
        let name = name.into();
        db.set_database(&name).expect("set_database");
        Self {
            inner: Arc::new(Mutex::new(db)),
        }
    }

    /// PHP `setDatabase($name)`.
    pub fn set_database(&self, name: impl Into<String>) {
        let name = name.into();
        self.inner.lock().set_database(&name).expect("set_database");
    }

    /// PHP `create()`.
    pub fn create(&self) {
        self.inner.lock().create(None).expect("create database");
    }

    /// PHP `delete()` - drops the database and collections.
    pub fn delete(&self) {
        let mut db = self.inner.lock();
        let name = db.get_database().to_owned();
        let _ = db.delete(Some(&name));
    }

    fn with_db<R>(
        &self,
        f: impl FnOnce(&mut UtopiaDatabase<Memory>) -> Result<R, DatabaseError>,
    ) -> Result<R, DatabaseError> {
        let mut db = self.inner.lock();
        f(&mut db)
    }
}

impl Database for MemoryDatabase {
    fn exists(&self, name: &str) -> Result<bool, DatabaseError> {
        self.with_db(|db| Ok(db.exists(Some(name), None)?))
    }

    fn get_database(&self) -> String {
        self.inner.lock().get_database().to_owned()
    }

    fn create_collection(
        &self,
        name: &str,
        attributes: Vec<Document>,
        indexes: Vec<Document>,
    ) -> Result<(), DatabaseError> {
        let attributes = attributes
            .into_iter()
            .map(to_db_doc)
            .collect::<Result<Vec<_>, _>>()?;
        let indexes = indexes
            .into_iter()
            .map(to_db_doc)
            .collect::<Result<Vec<_>, _>>()?;
        self.with_db(|db| {
            db.skip_authorization(|db| {
                match db.create_collection(name, attributes, indexes, None, true) {
                    Ok(_) => Ok(()),
                    Err(utopia_database::DatabaseError::Duplicate(_)) => {
                        Err(DatabaseError::Duplicate)
                    }
                    Err(err) => Err(DatabaseError::from(err)),
                }
            })
        })
    }

    fn find(&self, collection: &str, queries: &[Query]) -> Result<Vec<Document>, DatabaseError> {
        let db_queries = queries.iter().map(to_db_query).collect::<Vec<_>>();
        self.with_db(|db| {
            db.skip_authorization(|db| {
                Ok(db
                    .find(collection, &db_queries, "read")?
                    .into_iter()
                    .map(from_db_doc)
                    .collect())
            })
        })
    }

    fn find_one(&self, collection: &str, queries: &[Query]) -> Result<Document, DatabaseError> {
        let db_queries = queries.iter().map(to_db_query).collect::<Vec<_>>();
        self.with_db(|db| {
            db.skip_authorization(|db| Ok(from_db_doc(db.find_one(collection, &db_queries)?)))
        })
    }

    fn create_document(
        &self,
        collection: &str,
        document: Document,
    ) -> Result<Document, DatabaseError> {
        let key = document.get_attribute("key", Value::Null);
        let time = document.get_attribute("time", Value::Null);
        if key != Value::Null && time != Value::Null {
            let existing = self.find(collection, &[Query::equal("key", vec![key])])?;
            if existing
                .iter()
                .any(|doc| datetime_equal(&doc.get_attribute("time", Value::Null), &time))
            {
                return Err(DatabaseError::Duplicate);
            }
        }
        let db_doc = to_db_doc(document)?;
        self.with_db(|db| {
            db.skip_authorization(|db| match db.create_document(collection, db_doc) {
                Ok(created) => Ok(from_db_doc(created)),
                Err(
                    utopia_database::DatabaseError::Duplicate(_)
                    | utopia_database::DatabaseError::Unique(_),
                ) => Err(DatabaseError::Duplicate),
                Err(err) => Err(DatabaseError::from(err)),
            })
        })
    }

    fn increase_document_attribute(
        &self,
        collection: &str,
        id: &str,
        attribute: &str,
    ) -> Result<(), DatabaseError> {
        self.with_db(|db| {
            db.skip_authorization(|db| {
                db.increase_document_attribute(collection, id, attribute, 1.0, None, None)?;
                Ok(())
            })
        })
    }

    fn update_document(
        &self,
        collection: &str,
        id: &str,
        document: Document,
    ) -> Result<(), DatabaseError> {
        self.with_db(|db| {
            db.skip_authorization(|db| {
                let mut existing = db.get_document(collection, id, &[], false)?;
                if existing.is_empty() {
                    return Err(DatabaseError::Message("document not found".into()));
                }
                for (key, value) in document.data() {
                    existing.set_attribute(key.clone(), AttrValue::from_json(value.clone()));
                }
                db.update_document(collection, id, existing)?;
                Ok(())
            })
        })
    }

    fn delete_document(&self, collection: &str, id: &str) -> Result<(), DatabaseError> {
        self.with_db(|db| {
            db.skip_authorization(|db| {
                db.delete_document(collection, id)?;
                Ok(())
            })
        })
    }

    fn skip_authorization<F, T>(&self, func: F) -> Result<T, AbuseError>
    where
        F: FnOnce() -> Result<T, AbuseError>,
    {
        func()
    }
}

fn to_db_query(query: &Query) -> DbQuery {
    match query {
        Query::Equal { attribute, values } => DbQuery::equal(
            attribute.clone(),
            values.iter().cloned().map(AttrValue::from_json).collect(),
        ),
        Query::LessThan { attribute, value } => {
            DbQuery::less_than(attribute.clone(), AttrValue::from_json(value.clone()))
        }
        Query::OrderDesc { attribute } => DbQuery::order_desc(attribute.clone()),
        Query::Offset(value) => DbQuery::offset(*value),
        Query::Limit(value) => DbQuery::limit(*value),
    }
}

fn to_db_doc(document: Document) -> Result<DbDocument, DatabaseError> {
    DbDocument::try_from_json_object(document.to_array()).map_err(DatabaseError::from)
}

fn from_db_doc(document: DbDocument) -> Document {
    if document.is_empty() {
        return Document::empty();
    }
    Document::new(document.get_array_copy_json(&[], &[]))
}

fn datetime_equal(left: &Value, right: &Value) -> bool {
    match (left.as_str(), right.as_str()) {
        (Some(a), Some(b)) => {
            let norm = |s: &str| {
                s.replace('T', " ")
                    .replace("+00:00", "")
                    .trim_end_matches('Z')
                    .to_owned()
            };
            let left_n = norm(a);
            let right_n = norm(b);
            let n = left_n.len().min(right_n.len()).min(19);
            left_n.get(..n) == right_n.get(..n)
        }
        _ => left == right,
    }
}
