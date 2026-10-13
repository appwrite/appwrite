//! [`Database`]: logs as documents of a `utopia-database` collection.

use php_std::{Array, ArrayKey, Str, Value};
use utopia_database::datetime;
use utopia_database::value::{Array as DbArray, Ext, KeyRef, Value as DbValue};
use utopia_database::{Document, Kind, Query as DbQuery};
use utopia_query::Method;

use crate::schema::{self, Attribute, BASE_ATTRIBUTES, BASE_INDEXES, Index, Sql};
use crate::{Adapter, DateTime, Error, Log, Page, Query, Range, Result};

/// The Database adapter: each log is a document of the `audit` collection
/// of a [`utopia_database::Database`], read and written with authorization
/// skipped. PHP and Rust share the collection, its attributes and its
/// indexes.
///
/// The database must exist before [`Adapter::setup`] creates the
/// collection.
///
/// PHP: `Utopia\Audit\Adapter\Database`.
#[derive(Clone)]
pub struct Database {
    db: utopia_database::Database,
}

impl std::fmt::Debug for Database {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Database").field("database", &self.db.database()).finish()
    }
}

impl Database {
    /// An adapter storing logs in `db`'s current database.
    ///
    /// PHP: `new Database($db)`.
    pub fn new(db: utopia_database::Database) -> Database {
        Database { db }
    }

    /// The database logs are stored in.
    pub fn database(&self) -> &utopia_database::Database {
        &self.db
    }

    /// `find()` in the logs collection, with authorization skipped.
    async fn find_logs(&self, queries: Vec<DbQuery>) -> Result<Vec<Log>> {
        let db = &self.db;
        let documents = db
            .authorization()
            .skip(|| db.find(schema::COLLECTION, queries, utopia_database::Database::PERMISSION_READ))
            .await?;
        Ok(documents.iter().map(log_of).collect())
    }

    /// `count()` in the logs collection, with authorization skipped.
    async fn count_logs(&self, queries: Vec<DbQuery>, max: Option<i64>) -> Result<i64> {
        let db = &self.db;
        Ok(db.authorization().skip(|| db.count(schema::COLLECTION, queries, max)).await?)
    }

    /// The queries of a `getBy*()`: `filters`, the time range, the order
    /// (by sequence), the limit and the offset.
    fn page_queries(mut filters: Vec<DbQuery>, range: Range<'_>, page: Page) -> Vec<DbQuery> {
        filters.extend(time_queries(range));
        filters.push(if page.ascending { DbQuery::order_asc("") } else { DbQuery::order_desc("") });
        filters.push(DbQuery::limit(page.limit));
        filters.push(DbQuery::offset(page.offset));
        filters
    }
}

impl Sql for Database {
    fn attributes(&self) -> &'static [Attribute] {
        &BASE_ATTRIBUTES
    }

    fn indexes(&self) -> &'static [Index] {
        &BASE_INDEXES
    }

    /// `<id>: <type>(<size>)`, or `<id>: <type>` without a size: a
    /// description, since the database maps types itself.
    fn column_definition(&self, id: &str) -> Result<String> {
        let attribute = self.attribute(id).ok_or_else(|| schema::not_found(id))?;
        Ok(if attribute.size > 0 {
            format!("{id}: {}({})", attribute.kind, attribute.size)
        } else {
            format!("{id}: {}", attribute.kind)
        })
    }
}

/// The time-range queries: `between` with both bounds, else `greaterThan`
/// and/or `lessThan`.
fn time_queries(range: Range<'_>) -> Vec<DbQuery> {
    let after = range.after.map(datetime::format);
    let before = range.before.map(datetime::format);
    match (after, before) {
        (Some(after), Some(before)) => vec![DbQuery::between("time", after.as_str(), before.as_str())],
        (after, before) => {
            let mut queries = Vec::new();
            if let Some(after) = after {
                queries.push(DbQuery::greater_than("time", after.as_str()));
            }
            if let Some(before) = before {
                queries.push(DbQuery::less_than("time", before.as_str()));
            }
            queries
        }
    }
}

fn equal_events(events: &[&str]) -> DbQuery {
    DbQuery::equal("event", events.iter().copied())
}

/// A log of a document (`getArrayCopy()`).
fn log_of(document: &Document) -> Log {
    Log::new(plain_array(&document.array_copy(&[], &[])))
}

/// A database array as plain PHP values: documents and other objects become
/// their arrays.
fn plain_array(array: &DbArray) -> Array {
    let mut out = Array::with_capacity(array.len());
    for (key, value) in array.iter() {
        out.set(key_of(key), plain(value));
    }
    out
}

fn plain(value: &DbValue) -> Value {
    match value {
        DbValue::Null => Value::Null,
        DbValue::Bool(b) => Value::Bool(*b),
        DbValue::Int(i) => Value::Int(*i),
        DbValue::Float(f) => Value::Float(*f),
        DbValue::Str(s) => Value::Str(s.clone()),
        DbValue::Array(a) => Value::Array(plain_array(a)),
        DbValue::Object(a) => Value::Object(plain_array(a)),
        DbValue::Ext(Ext::Document(d)) => Value::Array(plain_array(&d.array_copy(&[], &[]))),
        DbValue::Ext(other) => Value::Array(plain_array(&php_std::Extension::to_array(other))),
    }
}

fn key_of(key: KeyRef<'_>) -> ArrayKey {
    match key {
        KeyRef::Int(i) => ArrayKey::Int(i),
        KeyRef::Str(s) => ArrayKey::Str(Str::from(s)),
    }
}

/// A plain array as a database array.
pub(crate) fn db_array<X: php_std::Extension>(array: &php_std::Array<X>) -> DbArray {
    let mut out = DbArray::with_capacity(array.len());
    for (key, value) in array.iter() {
        out.set(key_of(key), db_value(value));
    }
    out
}

fn db_value<X: php_std::Extension>(value: &php_std::Value<X>) -> DbValue {
    match value {
        php_std::Value::Null => DbValue::Null,
        php_std::Value::Bool(b) => DbValue::Bool(*b),
        php_std::Value::Int(i) => DbValue::Int(*i),
        php_std::Value::Float(f) => DbValue::Float(*f),
        php_std::Value::Str(s) => DbValue::Str(s.clone()),
        php_std::Value::Array(a) => DbValue::Array(db_array(a)),
        php_std::Value::Object(a) => DbValue::Object(db_array(a)),
        php_std::Value::Ext(x) => DbValue::Array(db_array(&x.to_array())),
    }
}

/// An audit query as a database query (`Query::parseQuery($query->toArray())`).
fn db_query(query: &Query) -> Result<DbQuery> {
    Ok(DbQuery::parse_query(&db_array(&query.to_array()))?)
}

/// `$log['time'] ??= DateTime::now()`.
fn with_time(mut log: Array) -> Array {
    if matches!(log.get_str("time"), None | Some(Value::Null)) {
        log.set(ArrayKey::Str(Str::from("time")), Value::from(datetime::now().as_str()));
    }
    log
}

/// A batch log's `time` in the database format: now when missing, parsed
/// like `new \DateTime($time)` when a string.
fn batch_time(log: &Array) -> Result<String> {
    let parsed = match log.get_str("time") {
        None | Some(Value::Null) => DateTime::new(b"now", None, &php_std::datetime::TzInfo::utc())?,
        Some(Value::Str(s)) => DateTime::new(s.as_bytes(), None, &php_std::datetime::TzInfo::utc())?,
        Some(other) => {
            return Err(Error::InvalidArgument(format!(
                "Log time must be a date string, {} given",
                other.debug_type()
            )));
        }
    };
    Ok(datetime::format(&parsed))
}

#[async_trait::async_trait]
impl Adapter for Database {
    fn name(&self) -> &str {
        "Database"
    }

    /// Creates the `audit` collection with its attributes and indexes; a
    /// collection that exists already is kept.
    ///
    /// Fails with `You need to create the database before running Audit
    /// setup` when the database does not exist.
    async fn setup(&self) -> Result<()> {
        let name = self.db.database();
        if !self.db.exists(Some(&name), None).await? {
            return Err(Error::exception("You need to create the database before running Audit setup"));
        }
        let attributes = self.attribute_documents()?;
        let indexes = self.index_documents()?;
        match self.db.create_collection(self.collection_name(), attributes, indexes, None, true).await {
            Ok(_) => Ok(()),
            Err(e) if e.is_a(Kind::Duplicate) => Ok(()),
            Err(e) => Err(e.into()),
        }
    }

    async fn get_by_id(&self, id: &str) -> Result<Option<Log>> {
        let db = &self.db;
        let document = db.authorization().skip(|| db.get_document(schema::COLLECTION, id, Vec::new(), false)).await?;
        Ok(if document.is_empty() { None } else { Some(log_of(&document)) })
    }

    /// Stores the log as a document (its `time` now when missing).
    async fn create(&self, log: Array) -> Result<Log> {
        let document = Document::new(db_array(&with_time(log)))?;
        let db = &self.db;
        let created = db.authorization().skip(|| db.create_document(schema::COLLECTION, document)).await?;
        Ok(log_of(&created))
    }

    /// Stores the logs as documents, each `time` (a date string, or now
    /// when missing) in the database format.
    async fn create_batch(&self, logs: Vec<Array>) -> Result<bool> {
        let mut documents = Vec::with_capacity(logs.len());
        for mut log in logs {
            let time = batch_time(&log)?;
            log.set(ArrayKey::Str(Str::from("time")), Value::from(time.as_str()));
            documents.push(Document::new(db_array(&log))?);
        }
        let db = &self.db;
        db.authorization()
            .skip(|| {
                db.create_documents(
                    schema::COLLECTION,
                    documents,
                    utopia_database::Database::INSERT_BATCH_SIZE,
                    None,
                    None,
                )
            })
            .await?;
        Ok(true)
    }

    async fn get_by_user(&self, user_id: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.find_logs(Self::page_queries(vec![DbQuery::equal("userId", [user_id])], range, page)).await
    }

    async fn count_by_user(&self, user_id: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        let mut queries = vec![DbQuery::equal("userId", [user_id])];
        queries.extend(time_queries(range));
        self.count_logs(queries, max).await
    }

    async fn get_by_resource(&self, resource: &str, range: Range<'_>, page: Page) -> Result<Vec<Log>> {
        self.find_logs(Self::page_queries(vec![DbQuery::equal("resource", [resource])], range, page)).await
    }

    async fn count_by_resource(&self, resource: &str, range: Range<'_>, max: Option<i64>) -> Result<i64> {
        let mut queries = vec![DbQuery::equal("resource", [resource])];
        queries.extend(time_queries(range));
        self.count_logs(queries, max).await
    }

    async fn get_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        let filters = vec![DbQuery::equal("userId", [user_id]), equal_events(events)];
        self.find_logs(Self::page_queries(filters, range, page)).await
    }

    async fn count_by_user_and_events(
        &self,
        user_id: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut queries = vec![DbQuery::equal("userId", [user_id]), equal_events(events)];
        queries.extend(time_queries(range));
        self.count_logs(queries, max).await
    }

    async fn get_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        page: Page,
    ) -> Result<Vec<Log>> {
        let filters = vec![DbQuery::equal("resource", [resource]), equal_events(events)];
        self.find_logs(Self::page_queries(filters, range, page)).await
    }

    async fn count_by_resource_and_events(
        &self,
        resource: &str,
        events: &[&str],
        range: Range<'_>,
        max: Option<i64>,
    ) -> Result<i64> {
        let mut queries = vec![DbQuery::equal("resource", [resource]), equal_events(events)];
        queries.extend(time_queries(range));
        self.count_logs(queries, max).await
    }

    /// Deletes the logs older than `datetime`, a batch at a time, until a
    /// batch deletes none.
    async fn cleanup(&self, datetime: &DateTime) -> Result<bool> {
        let before = datetime::format(datetime);
        let queries =
            vec![DbQuery::less_than("time", before.as_str()), DbQuery::order_desc("time"), DbQuery::order_asc("")];
        let db = &self.db;
        db.authorization()
            .skip(|| async {
                loop {
                    let removed = db
                        .delete_documents(
                            schema::COLLECTION,
                            queries.clone(),
                            utopia_database::Database::DELETE_BATCH_SIZE,
                            None,
                            None,
                        )
                        .await?;
                    if removed <= 0 {
                        return Ok::<_, Error>(());
                    }
                }
            })
            .await?;
        Ok(true)
    }

    /// Finds with each query parsed as a database query.
    async fn find(&self, queries: &[Query]) -> Result<Vec<Log>> {
        let queries = queries.iter().map(db_query).collect::<Result<Vec<_>>>()?;
        self.find_logs(queries).await
    }

    /// Counts with each query parsed as a database query, leaving out
    /// limits, offsets and cursors.
    async fn count(&self, queries: &[Query], max: Option<i64>) -> Result<i64> {
        let queries = queries
            .iter()
            .filter(|q| {
                !matches!(q.method(), Method::Limit | Method::Offset | Method::CursorAfter | Method::CursorBefore)
            })
            .map(db_query)
            .collect::<Result<Vec<_>>>()?;
        self.count_logs(queries, max).await
    }

    /// The database's ping; `false` when it fails.
    async fn ping(&self) -> bool {
        self.db.ping().await.unwrap_or(false)
    }
}
