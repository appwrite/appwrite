//! PostgreSQL adapter.

use std::sync::Arc;
use std::time::Duration;

use chrono::NaiveDateTime;
use deadpool_postgres::{Manager, ManagerConfig, Object, RecyclingMethod, Runtime};
use md5::{Digest, Md5};
use tokio_postgres::types::Type;
use tokio_postgres::{NoTls, Row};
use utopia_cache::Cache;

use crate::datetime;
use crate::query::{CursorDirection, Order, OrderDirection, Query, QueryGroups};
use crate::schema::{Attribute, AttributeType, Collection};
use crate::sql::{Builder, Param, quote};
use crate::{DEFAULT_LIMIT, Error, Result};

/// Maximum identifier length in PostgreSQL.
const MAX_IDENTIFIER: usize = 63;

/// Connection settings.
#[derive(Debug, Clone)]
pub struct PoolOptions {
    pub host: String,
    pub port: u16,
    pub user: String,
    pub password: String,
    pub dbname: String,
    pub max_size: usize,
    pub statement_timeout: Duration,
    pub connect_timeout: Duration,
    pub wait_timeout: Duration,
    pub application_name: String,
}

/// A pool of connections to one PostgreSQL server.
#[derive(Clone)]
pub struct Pool {
    inner: deadpool_postgres::Pool,
    /// Host as configured (`_APP_DB_HOST`); part of PHP cache keys.
    host: Arc<str>,
}

impl Pool {
    pub fn new(options: &PoolOptions) -> Result<Self> {
        let mut config = tokio_postgres::Config::new();
        config
            .host(&options.host)
            .port(options.port)
            .user(&options.user)
            .password(&options.password)
            .dbname(&options.dbname)
            .application_name(&options.application_name)
            .connect_timeout(options.connect_timeout)
            .options(format!("-c statement_timeout={}", options.statement_timeout.as_millis()));
        let manager = Manager::from_config(config, NoTls, ManagerConfig { recycling_method: RecyclingMethod::Fast });
        let inner = deadpool_postgres::Pool::builder(manager)
            .max_size(options.max_size)
            .runtime(Runtime::Tokio1)
            .wait_timeout(Some(options.wait_timeout))
            .create_timeout(Some(options.connect_timeout))
            .build()
            .map_err(|e| Error::Pool(e.to_string()))?;
        Ok(Self { inner, host: Arc::from(options.host.as_str()) })
    }

    pub async fn get(&self) -> Result<Object> {
        Ok(self.inner.get().await?)
    }

    pub fn host(&self) -> &str {
        &self.host
    }

    pub fn status(&self) -> deadpool_postgres::Status {
        self.inner.status()
    }
}

/// Types decoded from rows of a collection table.
pub trait FromRow: Sized {
    /// Projection: a comma separated, quoted column list (unqualified).
    ///
    /// Defaults to every column, as `utopia-php/database` does, so a table
    /// whose schema lags the collection config (a project not migrated yet)
    /// still decodes: absent columns read as `None`.
    const COLUMNS: &'static str = "*";
    fn from_row(row: &Row) -> Result<Self>;
}

/// Options for [`Database::find`].
pub struct FindOptions<'a> {
    pub filters: &'a [Query],
    pub orders: &'a [Order],
    pub cursor: Option<&'a crate::query::Cursor>,
    pub limit: Option<i64>,
    pub offset: Option<i64>,
    /// Authorization roles; `None` disables permission filtering.
    pub roles: Option<&'a [String]>,
}

impl<'a> FindOptions<'a> {
    pub fn from_groups(groups: &'a QueryGroups, roles: Option<&'a [String]>) -> Self {
        Self {
            filters: &groups.filters,
            orders: &groups.orders,
            cursor: groups.cursor.as_ref(),
            limit: groups.limit,
            offset: groups.offset,
            roles,
        }
    }

    pub fn filters(filters: &'a [Query]) -> Self {
        Self { filters, orders: &[], cursor: None, limit: None, offset: None, roles: None }
    }
}

/// A database scoped to a namespace (and tenant in shared-tables mode).
#[derive(Clone)]
pub struct Database {
    pool: Pool,
    schema: Arc<str>,
    namespace: Arc<str>,
    tenant: Option<i64>,
    cache: Option<Arc<Cache>>,
}

fn filter_identifier(value: &str) -> String {
    value.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-').collect()
}

/// `Postgres::getShortKey`.
fn short_key(key: &str) -> String {
    if key.len() <= MAX_IDENTIFIER {
        return key.to_owned();
    }
    let hash = hex::encode(Md5::digest(key.as_bytes()));
    if let Some(pos) = key.rfind('_') {
        let suffix = &key[pos + 1..];
        if !suffix.is_empty() {
            let hashed = format!("{hash}_{suffix}");
            if hashed.len() <= MAX_IDENTIFIER {
                return hashed;
            }
        }
    }
    hash[..MAX_IDENTIFIER.min(hash.len())].to_owned()
}

impl Database {
    pub fn new(pool: Pool, schema: &str, namespace: &str, tenant: Option<i64>, cache: Option<Arc<Cache>>) -> Self {
        Self {
            pool,
            schema: Arc::from(schema),
            namespace: Arc::from(filter_identifier(namespace).as_str()),
            tenant,
            cache,
        }
    }

    pub fn namespace(&self) -> &str {
        &self.namespace
    }

    pub fn tenant(&self) -> Option<i64> {
        self.tenant
    }

    pub fn pool(&self) -> &Pool {
        &self.pool
    }

    /// Fully qualified, quoted table name for a collection.
    pub fn table(&self, collection: &str) -> String {
        let name = short_key(&format!("{}_{}", self.namespace, filter_identifier(collection)));
        format!("\"{}\".\"{}\"", self.schema, name)
    }

    /// Permissions side table.
    pub fn perms_table(&self, collection: &str) -> String {
        self.table(&format!("{collection}_perms"))
    }

    /// `AND "<alias>"."_tenant" = $n` in shared-tables mode, else empty.
    pub fn tenant_condition(&self, builder: &mut Builder, alias: &str) -> String {
        match self.tenant {
            Some(t) => {
                let p = builder.bind(Param::Int(t));
                if alias.is_empty() {
                    format!(" AND \"_tenant\" = {p}")
                } else {
                    format!(" AND \"{alias}\".\"_tenant\" = {p}")
                }
            }
            None => String::new(),
        }
    }

    pub async fn client(&self) -> Result<Object> {
        self.pool.get().await
    }

    /// Runs a statement and returns all rows (one round trip).
    pub async fn query(&self, sql: &str, builder: &Builder) -> Result<Vec<Row>> {
        let client = self.client().await?;
        let rows = client.query_typed(sql, &builder.typed()).await?;
        Ok(rows)
    }

    /// Runs a statement and returns the first row, if any.
    pub async fn query_opt(&self, sql: &str, builder: &Builder) -> Result<Option<Row>> {
        Ok(self.query(sql, builder).await?.into_iter().next())
    }

    /// Fetches one document by `$id`.
    pub async fn get<T: FromRow>(&self, collection: &str, id: &str) -> Result<Option<T>> {
        let mut b = Builder::new();
        let p = b.bind(Param::text(id));
        let tenant = self.tenant_condition(&mut b, "main");
        let sql = format!(
            "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"_uid\" = {p}{tenant}",
            T::COLUMNS,
            self.table(collection)
        );
        match self.query_opt(&sql, &b).await? {
            Some(row) => Ok(Some(T::from_row(&row)?)),
            None => Ok(None),
        }
    }

    /// Whether a document exists.
    pub async fn exists(&self, collection: &str, id: &str) -> Result<bool> {
        let mut b = Builder::new();
        let p = b.bind(Param::text(id));
        let tenant = self.tenant_condition(&mut b, "main");
        let sql = format!("SELECT 1 FROM {} AS \"main\" WHERE \"main\".\"_uid\" = {p}{tenant}", self.table(collection));
        Ok(self.query_opt(&sql, &b).await?.is_some())
    }

    /// Builds the WHERE clause shared by `find` and `count`.
    fn where_clause(
        &self,
        b: &mut Builder,
        collection: &Collection,
        filters: &[Query],
        roles: Option<&[String]>,
        cursor_where: Option<String>,
    ) -> Result<String> {
        let mut parts: Vec<String> = Vec::new();
        if let Some(c) = cursor_where {
            parts.push(c);
        }
        if let Some(c) = b.conditions(collection, filters)? {
            parts.push(c);
        }
        if let Some(roles) = roles {
            if roles.is_empty() {
                parts.push("FALSE".to_owned());
            } else {
                let mut perms = Vec::with_capacity(roles.len());
                for role in roles {
                    let json = serde_json::to_string(&[format!("read(\"{role}\")")]).unwrap_or_default();
                    let p = b.bind(Param::Jsonb(json));
                    perms.push(format!("\"main\".\"_permissions\" @> {p}"));
                }
                parts.push(format!("({})", perms.join(" OR ")));
            }
        }
        if let Some(t) = self.tenant {
            let p = b.bind(Param::Int(t));
            parts.push(format!("\"main\".\"_tenant\" = {p}"));
        }
        Ok(if parts.is_empty() { String::new() } else { format!("WHERE {}", parts.join(" AND ")) })
    }

    /// `Database::find` with default ordering and cursor pagination semantics.
    pub async fn find<T: FromRow>(&self, collection: &Collection, options: FindOptions<'_>) -> Result<Vec<T>> {
        let rows = self.find_rows(collection, T::COLUMNS, options).await?;
        rows.iter().map(T::from_row).collect()
    }

    /// `find` returning raw rows with an explicit projection.
    pub async fn find_rows(
        &self,
        collection: &Collection,
        columns: &str,
        options: FindOptions<'_>,
    ) -> Result<Vec<Row>> {
        let mut orders: Vec<Order> = options.orders.to_vec();
        let unique = orders.iter().any(|o| o.attribute == "$id" || o.attribute == "$sequence");
        if !unique {
            let leading = orders.first().cloned();
            let direction = match &leading {
                Some(o) if (o.attribute == "$createdAt" || o.attribute == "$updatedAt") => o.direction,
                _ => OrderDirection::Asc,
            };
            let direction = if direction == OrderDirection::Random { OrderDirection::Asc } else { direction };
            orders.push(Order { attribute: "$sequence".to_owned(), direction });
        }

        let mut b = Builder::new();
        let cursor_direction = options.cursor.map(|c| c.direction);

        // Resolve cursor values from the cursor document.
        let mut cursor_where = None;
        if let Some(cursor) = options.cursor {
            let order_columns: Vec<&Order> = orders.iter().filter(|o| o.direction != OrderDirection::Random).collect();
            let projection: Vec<String> =
                order_columns.iter().map(|o| quote(Attribute::column(&o.attribute))).collect();
            let mut cb = Builder::new();
            let p = cb.bind(Param::text(cursor.id.as_str()));
            let tenant = self.tenant_condition(&mut cb, "main");
            let sql = format!(
                "SELECT {} FROM {} AS \"main\" WHERE \"main\".\"_uid\" = {p}{tenant}",
                if projection.is_empty() { "1".to_owned() } else { projection.join(", ") },
                self.table(collection.id)
            );
            let Some(row) = self.query_opt(&sql, &cb).await? else {
                return Err(Error::NotFound(format!("cursor:{}", cursor.id)));
            };
            let mut values = Vec::with_capacity(order_columns.len());
            for (i, order) in order_columns.iter().enumerate() {
                let attribute = collection
                    .attribute(&order.attribute)
                    .copied()
                    .ok_or_else(|| Error::Query(format!("Attribute not found in schema: {}", order.attribute)))?;
                let value = column_param(&row, i, &attribute)?;
                if matches!(value, Param::Null(_)) {
                    return Err(Error::Order { attribute: order.attribute.clone() });
                }
                values.push((order.attribute.clone(), value, order.direction));
            }
            let mut ors: Vec<String> = Vec::new();
            for i in 0..values.len() {
                let (attribute, value, direction) = &values[i];
                let direction = flip(*direction, cursor_direction == Some(CursorDirection::Before));
                let operator = if direction == OrderDirection::Desc { "<" } else { ">" };
                if values.len() == 1 && i == 0 && attribute == "$sequence" {
                    let p = b.bind(value.clone());
                    ors.push(format!("\"main\".\"_id\" {operator} {p}"));
                    break;
                }
                let mut conditions = Vec::new();
                for (prev_attribute, prev_value, _) in values.iter().take(i) {
                    let p = b.bind(prev_value.clone());
                    conditions.push(format!("\"main\".{} = {p}", quote(Attribute::column(prev_attribute))));
                }
                let p = b.bind(value.clone());
                conditions.push(format!("\"main\".{} {operator} {p}", quote(Attribute::column(attribute))));
                ors.push(format!("({})", conditions.join(" AND ")));
            }
            if !ors.is_empty() {
                cursor_where = Some(format!("({})", ors.join(" OR ")));
            }
        }

        let where_sql = self.where_clause(&mut b, collection, options.filters, options.roles, cursor_where)?;

        let mut order_sql = Vec::with_capacity(orders.len());
        for order in &orders {
            if order.direction == OrderDirection::Random {
                order_sql.push("RANDOM()".to_owned());
                continue;
            }
            let direction = flip(order.direction, cursor_direction == Some(CursorDirection::Before));
            let dir = if direction == OrderDirection::Desc { "DESC" } else { "ASC" };
            order_sql.push(format!("{} {dir}", quote(Attribute::column(&order.attribute))));
        }

        let limit = b.bind(Param::Int(options.limit.unwrap_or(DEFAULT_LIMIT)));
        let offset = match options.offset {
            Some(o) if o > 0 => format!(" OFFSET {}", b.bind(Param::Int(o))),
            _ => String::new(),
        };
        let sql = format!(
            "SELECT {columns} FROM {} AS \"main\" {where_sql} ORDER BY {} LIMIT {limit}{offset}",
            self.table(collection.id),
            order_sql.join(", ")
        );
        let mut rows = self.query(&sql, &b).await?;
        if cursor_direction == Some(CursorDirection::Before) {
            rows.reverse();
        }
        Ok(rows)
    }

    /// `Database::count` (filters only, bounded by `max`).
    pub async fn count(
        &self,
        collection: &Collection,
        filters: &[Query],
        max: Option<i64>,
        roles: Option<&[String]>,
    ) -> Result<i64> {
        let mut b = Builder::new();
        let where_sql = self.where_clause(&mut b, collection, filters, roles, None)?;
        let table = self.table(collection.id);
        let sql = match max {
            Some(max) => {
                let p = b.bind(Param::Int(max));
                format!(
                    "SELECT COUNT(1) AS sum FROM (SELECT 1 FROM {table} AS \"main\" {where_sql} LIMIT {p}) table_count"
                )
            }
            None => format!("SELECT COUNT(1) AS sum FROM {table} AS \"main\" {where_sql}"),
        };
        let row = self.query_opt(&sql, &b).await?;
        Ok(row.map(|r| r.try_get::<_, i64>(0).unwrap_or(0)).unwrap_or(0))
    }

    /// Inserts a document and its `_perms` rows in one statement.
    ///
    /// `columns` are attribute columns; system columns are filled in. Returns
    /// the inserted row projected with `returning`.
    pub async fn insert(
        &self,
        collection: &str,
        id: &str,
        permissions: &[String],
        columns: Vec<(&str, Param)>,
        returning: &str,
    ) -> Result<Row> {
        let now = datetime::now();
        let mut b = Builder::new();
        let mut names = Vec::with_capacity(columns.len() + 5);
        let mut values = Vec::with_capacity(columns.len() + 5);
        for (name, param) in columns {
            names.push(quote(name));
            values.push(b.bind(param));
        }
        names.push("\"_createdAt\"".to_owned());
        values.push(b.bind(Param::Timestamp(now)));
        names.push("\"_updatedAt\"".to_owned());
        values.push(b.bind(Param::Timestamp(now)));
        names.push("\"_permissions\"".to_owned());
        values.push(b.bind(Param::Jsonb(serde_json::to_string(permissions).unwrap_or_else(|_| "[]".into()))));
        names.push("\"_uid\"".to_owned());
        values.push(b.bind(Param::text(id)));
        if let Some(t) = self.tenant {
            names.push("\"_tenant\"".to_owned());
            values.push(b.bind(Param::Int(t)));
        }
        let (types, roles) = crate::permission::pairs(permissions);
        let sql = if types.is_empty() {
            format!(
                "INSERT INTO {} ({}) VALUES ({}) RETURNING {returning}",
                self.table(collection),
                names.join(", "),
                values.join(", ")
            )
        } else {
            let t = b.bind(Param::TextArray(types));
            let r = b.bind(Param::TextArray(roles));
            let (tenant_col, tenant_val) =
                if self.tenant.is_some() { (", \"_tenant\"", ", ins.\"_tenant\"") } else { ("", "") };
            format!(
                "WITH ins AS (INSERT INTO {} ({}) VALUES ({}) RETURNING *), \
                 perms AS (INSERT INTO {} (\"_type\", \"_permission\", \"_document\"{tenant_col}) \
                 SELECT p.t, p.r, ins.\"_uid\"{tenant_val} FROM ins, unnest({t}, {r}) AS p(t, r)) \
                 SELECT {returning} FROM ins",
                self.table(collection),
                names.join(", "),
                values.join(", "),
                self.perms_table(collection)
            )
        };
        let row = self.query_opt(&sql, &b).await?.ok_or_else(|| Error::Decode("insert returned no row".to_owned()))?;
        // Clears negative cache entries (PHP `purgeCachedDocumentInternal`).
        self.purge_cached_document(collection, id).await;
        Ok(row)
    }

    /// Sparse update by `$id`. `_updatedAt` is bumped only when a value changes.
    /// Inserts, updates and deletes purge the document's cache entry.
    /// Returns the updated row (projected with `returning`) or `None` if missing.
    pub async fn update(
        &self,
        collection: &str,
        id: &str,
        columns: Vec<(&str, Param)>,
        returning: &str,
    ) -> Result<Option<Row>> {
        let mut b = Builder::new();
        let mut sets = Vec::with_capacity(columns.len() + 1);
        let mut olds = Vec::with_capacity(columns.len());
        let mut news = Vec::with_capacity(columns.len());
        for (name, param) in columns {
            let column = quote(name);
            let cast = match &param {
                Param::Null(t) if *t == Type::TEXT => "::text",
                _ => "",
            };
            let p = b.bind(param);
            sets.push(format!("{column} = {p}"));
            olds.push(format!("\"main\".{column}"));
            news.push(format!("{p}{cast}"));
        }
        let now = b.bind(Param::Timestamp(datetime::now()));
        if olds.is_empty() {
            return self.get_row(collection, id, returning).await;
        }
        sets.push(format!(
            "\"_updatedAt\" = CASE WHEN ROW({}, NULL) IS DISTINCT FROM ROW({}, NULL) THEN {now} ELSE \"main\".\"_updatedAt\" END",
            olds.join(", "),
            news.join(", ")
        ));
        let p = b.bind(Param::text(id));
        let tenant = self.tenant_condition(&mut b, "main");
        let sql = format!(
            "UPDATE {} AS \"main\" SET {} WHERE \"main\".\"_uid\" = {p}{tenant} RETURNING {returning}",
            self.table(collection),
            sets.join(", ")
        );
        let row = self.query_opt(&sql, &b).await?;
        if row.is_some() {
            self.purge_cached_document(collection, id).await;
        }
        Ok(row)
    }

    /// Reads a row by `$id` with an explicit projection.
    pub async fn get_row(&self, collection: &str, id: &str, columns: &str) -> Result<Option<Row>> {
        let mut b = Builder::new();
        let p = b.bind(Param::text(id));
        let tenant = self.tenant_condition(&mut b, "main");
        let sql = format!(
            "SELECT {columns} FROM {} AS \"main\" WHERE \"main\".\"_uid\" = {p}{tenant}",
            self.table(collection)
        );
        self.query_opt(&sql, &b).await
    }

    /// Deletes a document and its `_perms` rows. Returns whether it existed.
    pub async fn delete(&self, collection: &str, id: &str) -> Result<bool> {
        let mut b = Builder::new();
        let p = b.bind(Param::text(id));
        let (tenant_main, tenant_perms) = match self.tenant {
            Some(t) => {
                let tp = b.bind(Param::Int(t));
                (format!(" AND \"_tenant\" = {tp}"), format!(" AND \"_tenant\" = {tp}"))
            }
            None => (String::new(), String::new()),
        };
        let sql = format!(
            "WITH d AS (DELETE FROM {} WHERE \"_uid\" = {p}{tenant_main} RETURNING \"_uid\"), \
             p AS (DELETE FROM {} WHERE \"_document\" IN (SELECT \"_uid\" FROM d){tenant_perms}) \
             SELECT COUNT(*) FROM d",
            self.table(collection),
            self.perms_table(collection)
        );
        let row = self.query_opt(&sql, &b).await?;
        let deleted = row.map(|r| r.try_get::<_, i64>(0).unwrap_or(0) > 0).unwrap_or(false);
        if deleted {
            self.purge_cached_document(collection, id).await;
        }
        Ok(deleted)
    }

    /// PHP cache keys for a document: `(collectionKey, documentKey)`.
    pub fn cache_keys(&self, collection: &str, id: &str) -> (String, String) {
        let tenant = self.tenant.map(|t| t.to_string()).unwrap_or_default();
        let collection_key =
            format!("default-cache-{}:{}:{}:collection:{}", self.pool.host(), self.namespace, tenant, collection);
        let document_key = format!("{collection_key}:{id}");
        (collection_key, document_key)
    }

    /// Purges the PHP (and Rust) cache entry of a document
    /// (`Database::purgeCachedDocument`).
    pub async fn purge_cached_document(&self, collection: &str, id: &str) {
        let Some(cache) = &self.cache else { return };
        let (collection_key, document_key) = self.cache_keys(collection, id);
        if let Err(e) = cache.purge_document(&collection_key, &document_key).await {
            tracing::warn!(error = %e, collection, id, "cache purge failed");
        }
    }

    pub fn cache(&self) -> Option<&Arc<Cache>> {
        self.cache.as_ref()
    }
}

fn flip(direction: OrderDirection, flip: bool) -> OrderDirection {
    if !flip {
        return direction;
    }
    match direction {
        OrderDirection::Asc => OrderDirection::Desc,
        OrderDirection::Desc => OrderDirection::Asc,
        OrderDirection::Random => OrderDirection::Random,
    }
}

/// Reads a cursor column as a typed parameter.
fn column_param(row: &Row, index: usize, attribute: &Attribute) -> Result<Param> {
    let decode = |e: tokio_postgres::Error| Error::Decode(e.to_string());
    if attribute.id == "$sequence" {
        return Ok(row
            .try_get::<_, Option<i64>>(index)
            .map_err(decode)?
            .map(Param::Int)
            .unwrap_or(Param::Null(Type::INT8)));
    }
    Ok(match attribute.kind {
        AttributeType::String => {
            row.try_get::<_, Option<String>>(index).map_err(decode)?.map(Param::Text).unwrap_or(Param::Null(Type::TEXT))
        }
        AttributeType::Integer | AttributeType::Id => {
            let v = match row.columns()[index].type_() {
                t if *t == Type::INT4 => row.try_get::<_, Option<i32>>(index).map_err(decode)?.map(i64::from),
                _ => row.try_get::<_, Option<i64>>(index).map_err(decode)?,
            };
            v.map(Param::Int).unwrap_or(Param::Null(Type::INT8))
        }
        AttributeType::Float => {
            row.try_get::<_, Option<f64>>(index).map_err(decode)?.map(Param::Float).unwrap_or(Param::Null(Type::FLOAT8))
        }
        AttributeType::Boolean => {
            row.try_get::<_, Option<bool>>(index).map_err(decode)?.map(Param::Bool).unwrap_or(Param::Null(Type::BOOL))
        }
        AttributeType::Datetime => row
            .try_get::<_, Option<NaiveDateTime>>(index)
            .map_err(decode)?
            .map(Param::Timestamp)
            .unwrap_or(Param::Null(Type::TIMESTAMP)),
    })
}

/// Typed accessors for system collection rows.
pub mod row {
    use chrono::NaiveDateTime;
    use serde_json::Value;
    use tokio_postgres::Row;
    use tokio_postgres::types::Type;

    use crate::{Error, Result};

    fn decode(column: &str, e: tokio_postgres::Error) -> Error {
        Error::Decode(format!("{column}: {e}"))
    }

    fn index(row: &Row, column: &str) -> Option<usize> {
        row.columns().iter().position(|c| c.name() == column)
    }

    /// Reads a nullable column. A column the table does not have yet reads
    /// as `None`, like an attribute missing from a PHP document.
    fn get<'a, T: tokio_postgres::types::FromSql<'a>>(row: &'a Row, column: &str) -> Result<Option<T>> {
        match index(row, column) {
            Some(idx) => row.try_get::<_, Option<T>>(idx).map_err(|e| decode(column, e)),
            None => Ok(None),
        }
    }

    pub fn string(row: &Row, column: &str) -> Result<Option<String>> {
        get(row, column)
    }

    pub fn string_or_empty(row: &Row, column: &str) -> Result<String> {
        Ok(string(row, column)?.unwrap_or_default())
    }

    pub fn boolean(row: &Row, column: &str) -> Result<Option<bool>> {
        get(row, column)
    }

    pub fn int(row: &Row, column: &str) -> Result<Option<i64>> {
        let Some(idx) = index(row, column) else {
            return Ok(None);
        };
        match row.columns()[idx].type_() {
            t if *t == Type::INT4 => {
                Ok(row.try_get::<_, Option<i32>>(idx).map_err(|e| decode(column, e))?.map(i64::from))
            }
            t if *t == Type::INT2 => {
                Ok(row.try_get::<_, Option<i16>>(idx).map_err(|e| decode(column, e))?.map(i64::from))
            }
            _ => row.try_get::<_, Option<i64>>(idx).map_err(|e| decode(column, e)),
        }
    }

    pub fn float(row: &Row, column: &str) -> Result<Option<f64>> {
        get(row, column)
    }

    pub fn datetime(row: &Row, column: &str) -> Result<Option<NaiveDateTime>> {
        get(row, column)
    }

    /// JSONB column holding a JSON value.
    pub fn jsonb(row: &Row, column: &str) -> Result<Option<Value>> {
        get(row, column)
    }

    /// JSONB array of strings (array attributes, `_permissions`).
    pub fn strings(row: &Row, column: &str) -> Result<Vec<String>> {
        Ok(match jsonb(row, column)? {
            Some(Value::Array(items)) => items
                .into_iter()
                .map(|v| match v {
                    Value::String(s) => s,
                    other => other.to_string(),
                })
                .collect(),
            _ => Vec::new(),
        })
    }

    /// TEXT column holding JSON (attributes with the `json` filter).
    pub fn json_text(row: &Row, column: &str) -> Result<Option<Value>> {
        Ok(string(row, column)?.and_then(|s| serde_json::from_str(&s).ok()))
    }

    /// `_id` as i64.
    pub fn sequence(row: &Row) -> Result<i64> {
        row.try_get::<_, i64>("_id").map_err(|e| decode("_id", e))
    }

    /// `_uid`.
    pub fn uid(row: &Row) -> Result<String> {
        row.try_get::<_, String>("_uid").map_err(|e| decode("_uid", e))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn short_keys() {
        assert_eq!(short_key("_1_users"), "_1_users");
        let long = format!("_1_{}", "x".repeat(80));
        let k = short_key(&long);
        assert!(k.len() <= 63);
    }
}
