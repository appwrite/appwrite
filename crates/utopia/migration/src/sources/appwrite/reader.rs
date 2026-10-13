//! How the Appwrite source reads databases: through the API, or straight from
//! the project's database.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::{Array, Value};
use utopia_database::{Database as Db, Document, Query};

use crate::error::{Error, Result};
use crate::exception::Exception;
use crate::resource::{DATABASE_TYPE_RESOURCE_MAP, Kind, ResourceRef, database_resources, is_supported, types};
use crate::resources::cast_string;
use crate::source::ResourceIds;
use crate::transfer::Report;

/// A query of a reader: an API query string, or a database query.
///
/// PHP: the `mixed` the `query*()` methods of a `Reader` return (`string`
/// for the API reader, `Utopia\Database\Query` for the database reader).
#[derive(Debug, Clone)]
pub enum ReaderQuery {
    /// An Appwrite API query (`Appwrite\Query::*()`, JSON).
    Api(String),
    /// A database query.
    Database(Query),
}

/// Where a cursor points: a resource, or an id.
#[derive(Debug, Clone)]
pub enum Cursor {
    /// The last resource of the previous page.
    Resource(ResourceRef),
    /// The id of the last resource of the previous page.
    Id(String),
    /// Anything else, such as a column the database reader listed (PHP passes
    /// the `Utopia\Database\Document`): the database reader rejects it.
    Other(Array),
}

/// Lists databases, tables, columns, indexes and rows for the Appwrite
/// source, and builds the queries it pages them with.
///
/// PHP: `Utopia\Migration\Sources\Appwrite\Reader`.
#[async_trait]
pub trait Reader: Send + Sync {
    /// Adds database resource counts to `report`.
    async fn report(&self, resources: &[String], report: &mut Report, resource_ids: &ResourceIds) -> Result<()>;
    /// Databases.
    async fn list_databases(&self, queries: Vec<ReaderQuery>) -> Result<Vec<Array>>;
    /// Tables (or collections) of a database resource.
    async fn list_tables(&self, database: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>>;
    /// Columns of a table resource.
    async fn list_columns(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>>;
    /// Indexes of a table resource.
    async fn list_indexes(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>>;
    /// Rows of a table resource.
    async fn list_rows(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>>;
    /// One row.
    async fn get_row(&self, table: &ResourceRef, row_id: &str, queries: Vec<ReaderQuery>) -> Result<Array>;
    /// `select` of `columns`.
    fn query_select(&self, columns: Vec<String>) -> ReaderQuery;
    /// `equal` of `column` to any of `values`.
    fn query_equal(&self, column: &str, values: Vec<Value>) -> ReaderQuery;
    /// `cursorAfter` the cursor.
    async fn query_cursor_after(&self, cursor: Cursor) -> Result<ReaderQuery>;
    /// `limit`.
    fn query_limit(&self, limit: i64) -> ReaderQuery;
    /// Whether tables have columns (schemaless databases have none).
    fn support_for_attributes(&self) -> bool;
}

/// Resolves the database a database document's data lives in.
pub type DatabaseResolver = Arc<dyn Fn(&Document) -> Db + Send + Sync>;

/// Reads straight from the project's database (`_databases`, `_attributes`,
/// `_indexes` and the per-database collections).
///
/// PHP: `Utopia\Migration\Sources\Appwrite\Reader\Database`.
pub struct DatabaseReader {
    db: Db,
    resolver: Option<DatabaseResolver>,
    /// The project id (unused by reads, kept as PHP's).
    pub project_id: Option<String>,
}

impl std::fmt::Debug for DatabaseReader {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("DatabaseReader").field("project_id", &self.project_id).finish_non_exhaustive()
    }
}

/// A document as a plain array (`getArrayCopy()`).
pub fn plain(doc: &Document) -> Array {
    match utopia_database::value::to_plain(&utopia_database::Value::Array(doc.array_copy(&[], &[]))) {
        Value::Array(a) => a,
        _ => Array::new(),
    }
}

fn sequence(doc: &Document) -> String {
    doc.sequence().map(|v| cast_string(&utopia_database::value::to_plain(v))).unwrap_or_default()
}

fn db_queries(queries: Vec<ReaderQuery>) -> Vec<Query> {
    queries
        .into_iter()
        .filter_map(|q| match q {
            ReaderQuery::Database(q) => Some(q),
            ReaderQuery::Api(_) => None,
        })
        .collect()
}

fn wrap(resource: &ResourceRef, e: utopia_database::Error) -> Error {
    let code = match e.code() {
        0 => Exception::CODE_INTERNAL,
        c => c,
    };
    Error::migration(Exception::new(
        resource.name(),
        resource.group(),
        Some(resource.id()),
        e.message().to_owned(),
        code,
        Some(Error::Database(e)),
    ))
}

fn not_found(resource: &ResourceRef, what: &str) -> Error {
    Error::migration(Exception::new(resource.name(), resource.group(), Some(resource.id()), what, 0, None))
}

impl DatabaseReader {
    /// A reader of `db`; `resolver` gives the database a database's rows live in.
    ///
    /// PHP: `new Reader\Database($dbForProject, $getDatabasesDB, $projectId)`.
    pub fn new(db: Db, resolver: Option<DatabaseResolver>, project_id: Option<String>) -> Self {
        Self { db, resolver, project_id }
    }

    fn rows_db(&self, database: Option<&Document>) -> Db {
        match (&self.resolver, database) {
            (Some(r), Some(d)) => r(d),
            _ => self.db.clone(),
        }
    }

    async fn database_document(&self, resource: &ResourceRef, id: &str) -> Result<Document> {
        let d = self.db.get_document("databases", id, Vec::new(), false).await?;
        if d.is_empty() {
            return Err(not_found(resource, "Database not found"));
        }
        Ok(d)
    }

    async fn table_documents(&self, table: &ResourceRef) -> Result<(Document, Document)> {
        let database_id = table.database().map(|d| d.id()).unwrap_or_default();
        let database = self.database_document(table, &database_id).await?;
        let t =
            self.db.get_document(&format!("database_{}", sequence(&database)), &table.id(), Vec::new(), false).await?;
        if t.is_empty() {
            return Err(not_found(table, "Table not found"));
        }
        Ok((database, t))
    }

    fn database_type(d: &Array) -> &'static str {
        let t = match d.get_str("type") {
            Some(Value::Null) | None => types::DATABASE.to_owned(),
            Some(v) => cast_string(v),
        };
        if t == types::DATABASE_LEGACY || t == types::DATABASE_TABLESDB {
            return types::DATABASE;
        }
        database_resources(&t).map_or(types::DATABASE, |d| d.database)
    }

    async fn count(&self, collection: &str, queries: Vec<Query>, database: Option<&Document>) -> Result<i64> {
        Ok(self.rows_db(database).count(collection, queries, None).await?)
    }
}

#[async_trait]
impl Reader for DatabaseReader {
    async fn report(&self, resources: &[String], report: &mut Report, resource_ids: &ResourceIds) -> Result<()> {
        let relevant = [
            types::DATABASE,
            types::TABLE,
            types::ROW,
            types::COLUMN,
            types::INDEX,
            types::DATABASE_VECTORSDB,
            types::DATABASE_DOCUMENTSDB,
            types::COLLECTION,
            types::DOCUMENT,
            types::ATTRIBUTE,
        ];
        if !is_supported(&relevant, resources) {
            return Ok(());
        }
        for t in relevant {
            if is_supported(&[t], resources) {
                report.insert(t.to_owned(), Value::Int(0));
            }
        }
        let database_types: Vec<&str> = DATABASE_TYPE_RESOURCE_MAP.iter().map(|d| d.database).collect();
        let mut queries = Vec::new();
        for t in &database_types {
            if let Some(ids) = resource_ids.get(*t)
                && crate::resources::cast_bool(ids)
            {
                let list: Vec<Value> = match ids {
                    Value::Array(a) => a.values().cloned().collect(),
                    v => vec![v.clone()],
                };
                queries.push(Query::equal("$id", list.into_iter().map(utopia_database::value::from_plain)));
            }
        }
        let selected_relevant = resources.iter().filter(|r| relevant.contains(&r.as_str())).count();
        if selected_relevant == 1 && is_supported(&database_types, resources) {
            return Ok(());
        }
        let databases = self.db.find("databases", queries, Db::PERMISSION_READ).await?;
        let selected: Vec<String> =
            resources.iter().filter(|r| database_types.contains(&r.as_str())).cloned().collect();
        let plain_dbs: Vec<Array> = databases.iter().map(plain).collect();
        for d in &plain_dbs {
            let t = Self::database_type(d);
            if !selected.is_empty() && !is_supported(&[t], &selected) {
                continue;
            }
            if is_supported(&[t], resources) {
                let e = report.entry(t.to_owned()).or_insert(Value::Int(0));
                *e = Value::Int(crate::resources::cast_int(e) + 1);
            }
        }
        for (doc, d) in databases.iter().zip(&plain_dbs) {
            let t = Self::database_type(d);
            if !selected.is_empty() && !is_supported(&[t], &selected) {
                continue;
            }
            let spec = database_resources(t).expect("known database type");
            let db_seq = sequence(doc);
            let tables = self.db.find(&format!("database_{db_seq}"), Vec::new(), Db::PERMISSION_READ).await?;
            if is_supported(&[spec.entity], resources) {
                let e = report.entry(spec.entity.to_owned()).or_insert(Value::Int(0));
                *e = Value::Int(crate::resources::cast_int(e) + tables.len() as i64);
            }
            for table in &tables {
                let t_seq = sequence(table);
                if is_supported(&[spec.record], resources) {
                    let n = self.count(&format!("database_{db_seq}_collection_{t_seq}"), Vec::new(), Some(doc)).await?;
                    let e = report.entry(spec.record.to_owned()).or_insert(Value::Int(0));
                    *e = Value::Int(crate::resources::cast_int(e) + n);
                }
                let common = || {
                    vec![
                        Query::equal("databaseInternalId", [utopia_database::Value::from(db_seq.as_str())]),
                        Query::equal("collectionInternalId", [utopia_database::Value::from(t_seq.as_str())]),
                    ]
                };
                if is_supported(&[spec.field], resources) {
                    let n = self.count("attributes", common(), None).await?;
                    let e = report.entry(spec.field.to_owned()).or_insert(Value::Int(0));
                    *e = Value::Int(crate::resources::cast_int(e) + n);
                }
                if resources.iter().any(|r| r == types::INDEX) {
                    let n = self.count("indexes", common(), None).await?;
                    let e = report.entry(types::INDEX.to_owned()).or_insert(Value::Int(0));
                    *e = Value::Int(crate::resources::cast_int(e) + n);
                }
            }
        }
        Ok(())
    }

    async fn list_databases(&self, queries: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        Ok(self.db.find("databases", db_queries(queries), Db::PERMISSION_READ).await?.iter().map(plain).collect())
    }

    async fn list_tables(&self, database: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let d = match self.db.get_document("databases", &database.id(), Vec::new(), false).await {
            Ok(d) => d,
            Err(e) => return Err(wrap(database, e)),
        };
        if d.is_empty() {
            return Err(not_found(database, "Database not found"));
        }
        match self.db.find(&format!("database_{}", sequence(&d)), db_queries(queries), Db::PERMISSION_READ).await {
            Ok(rows) => Ok(rows.iter().map(plain).collect()),
            Err(e) => Err(wrap(database, e)),
        }
    }

    async fn list_columns(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database, t) = self.table_documents(table).await?;
        let mut queries = db_queries(queries);
        queries.push(Query::equal("databaseInternalId", [utopia_database::Value::from(sequence(&database).as_str())]));
        queries.push(Query::equal("collectionInternalId", [utopia_database::Value::from(sequence(&t).as_str())]));
        let columns = match self.db.find("attributes", queries, Db::PERMISSION_READ).await {
            Ok(c) => c,
            Err(e) => return Err(wrap(table, e)),
        };
        Ok(columns
            .iter()
            .map(|c| {
                let mut a = plain(c);
                if a.get_str("type").map(cast_string).as_deref() == Some(Db::VAR_RELATIONSHIP) {
                    if let Some(Value::Array(options)) = a.get_str("options").cloned() {
                        for (k, v) in options.iter() {
                            a.set(k.to_owned(), v.clone());
                        }
                    }
                    a.remove(&"options".into());
                }
                a
            })
            .collect())
    }

    async fn list_indexes(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database, t) = self.table_documents(table).await?;
        let mut queries = db_queries(queries);
        queries.push(Query::equal("databaseInternalId", [utopia_database::Value::from(sequence(&database).as_str())]));
        queries.push(Query::equal("collectionInternalId", [utopia_database::Value::from(sequence(&t).as_str())]));
        match self.db.find("indexes", queries, Db::PERMISSION_READ).await {
            Ok(rows) => Ok(rows.iter().map(plain).collect()),
            Err(e) => Err(wrap(table, e)),
        }
    }

    async fn list_rows(&self, table: &ResourceRef, queries: Vec<ReaderQuery>) -> Result<Vec<Array>> {
        let (database, t) = self.table_documents(table).await?;
        let id = format!("database_{}_collection_{}", sequence(&database), sequence(&t));
        let db = self.rows_db(Some(&database));
        match db.find(&id, db_queries(queries), Db::PERMISSION_READ).await {
            Ok(rows) => Ok(rows.iter().map(plain).collect()),
            Err(e) => Err(wrap(table, e)),
        }
    }

    async fn get_row(&self, table: &ResourceRef, row_id: &str, queries: Vec<ReaderQuery>) -> Result<Array> {
        let (database, t) = self.table_documents(table).await?;
        let id = format!("database_{}_collection_{}", sequence(&database), sequence(&t));
        let db = self.rows_db(Some(&database));
        Ok(plain(&db.get_document(&id, row_id, db_queries(queries), false).await?))
    }

    fn query_select(&self, columns: Vec<String>) -> ReaderQuery {
        ReaderQuery::Database(Query::select(columns.iter().map(|c| utopia_database::Value::from(c.as_str()))))
    }

    fn query_equal(&self, column: &str, values: Vec<Value>) -> ReaderQuery {
        ReaderQuery::Database(Query::equal(column, values.into_iter().map(utopia_database::value::from_plain)))
    }

    /// PHP switches on the resource's exact class, so a `DocumentsDB` or
    /// `VectorsDB` database and every column (columns are subclasses of the
    /// abstract `Column`) are unsupported.
    async fn query_cursor_after(&self, cursor: Cursor) -> Result<ReaderQuery> {
        let r = match cursor {
            Cursor::Id(_) => {
                return Err(Error::InvalidArgument(
                    "Querying with a cursor through the database requires a resource reference".into(),
                ));
            }
            Cursor::Resource(r) => r,
            Cursor::Other(_) => return Err(Error::InvalidArgument("Unsupported resource type".into())),
        };
        let unsupported = || Error::InvalidArgument("Unsupported resource type".into());
        let document = match r.kind() {
            Kind::Database(d) if d.flavor == crate::resources::database::DatabaseFlavor::Database => {
                self.db.get_document("databases", &r.id(), Vec::new(), false).await?
            }
            Kind::Table(_) => {
                let db_id = r.database().map(|d| d.id()).unwrap_or_default();
                let database = self.db.get_document("databases", &db_id, Vec::new(), false).await?;
                self.db.get_document(&format!("database_{}", sequence(&database)), &r.id(), Vec::new(), false).await?
            }
            Kind::Index(_) => self.db.get_document("indexes", &r.id(), Vec::new(), false).await?,
            Kind::Row(row) => {
                let a = self.get_row(&row.table, &r.id(), Vec::new()).await?;
                Document::new(utopia_database::value::from_plain(Value::Array(a)).as_array_owned())
                    .map_err(Error::Database)?
            }
            _ => return Err(unsupported()),
        };
        Ok(ReaderQuery::Database(Query::cursor_after(document)))
    }

    fn query_limit(&self, limit: i64) -> ReaderQuery {
        ReaderQuery::Database(Query::limit(limit))
    }

    fn support_for_attributes(&self) -> bool {
        self.db.adapter().support_for_attributes()
    }
}

trait OwnedArray {
    fn as_array_owned(self) -> utopia_database::Array;
}

impl OwnedArray for utopia_database::Value {
    fn as_array_owned(self) -> utopia_database::Array {
        match self {
            utopia_database::Value::Array(a) => a,
            _ => utopia_database::Array::new(),
        }
    }
}
