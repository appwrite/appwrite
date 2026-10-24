//! The Database adapter: every metric in one collection (`usage`) of a
//! `utopia-database` database, events and gauges told apart by a `type`
//! attribute.
//!
//! PHP: `Utopia\Usage\Adapter\Database`.

use std::sync::Arc;

use async_trait::async_trait;
use php_std::ArrayKey;
use utopia_database::Database as Db;
use utopia_database::query::Query as DbQuery;
use utopia_database::value::{from_query_array, to_query_array};
use utopia_database::{
    Attribute as DbAttribute, Collection, Document, Index as DbIndex, Kind as DbKind, PermissionType, Value as DbValue,
};
use utopia_query::{Array, Method, Query, Value};

use super::{Adapter, Entry, Series, TimeSeries, Totals};
use crate::error::{Error, Result};
use crate::metric::{EVENT_COLUMNS, GAUGE_COLUMNS, Kind, Metric, Number};
use crate::php;

/// The collection metrics are stored in.
///
/// PHP: `SQL::COLLECTION`.
pub const COLLECTION: &str = "usage";

/// Usage metrics in a `utopia-database` collection. The adapter owns the
/// database's tenant: every call sets it (a numeric tenant), none restores
/// it, so give it a database dedicated to usage.
///
/// Time series are not implemented (every metric reads as empty), and the
/// daily methods read the collection itself.
///
/// PHP: `Utopia\Usage\Adapter\Database`.
#[derive(Clone)]
pub struct Database {
    db: Arc<Db>,
}

impl std::fmt::Debug for Database {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Database").finish_non_exhaustive()
    }
}

/// The attributes of `kind`'s schema as database models, for
/// `createCollection()`.
///
/// PHP: `SQL::getAttributeDocuments($type)`.
pub fn attribute_documents(kind: Kind) -> Result<Vec<DbAttribute>> {
    Ok(kind
        .schema()
        .iter()
        .map(|a| DbAttribute::from_array(&from_query_array(&a.to_array())))
        .collect::<utopia_database::Result<_>>()?)
}

/// The indexes of `kind` as database models, for `createCollection()`
/// (without ClickHouse's `indexType`).
///
/// PHP: `SQL::getIndexDocuments($type)`.
pub fn index_documents(kind: Kind) -> Result<Vec<DbIndex>> {
    Ok(kind
        .indexes()
        .iter()
        .map(|i| DbIndex::from_array(&from_query_array(&i.to_array())))
        .collect::<utopia_database::Result<_>>()?)
}

/// `(int) $v`.
fn cast_int(value: &Value) -> i64 {
    match value {
        Value::Null | Value::Bool(false) => 0,
        Value::Bool(true) => 1,
        Value::Int(i) => *i,
        Value::Float(f) => php_std::format::dval_to_lval(*f),
        Value::Str(s) => php_std::format::str_to_int(s.as_bytes()),
        Value::Array(a) => i64::from(!a.is_empty()),
        Value::Object(_) | Value::Ext(_) => 1,
    }
}

fn first(values: &Array) -> Option<&Value> {
    values.get(&ArrayKey::Int(0))
}

fn second(values: &Array) -> Option<&Value> {
    values.get(&ArrayKey::Int(1))
}

impl Database {
    /// The adapter over `db` (whose database must exist before [`Adapter::setup`]).
    ///
    /// PHP: `new Database($db)`.
    pub fn new(db: Arc<Db>) -> Database {
        Database { db }
    }

    /// The database.
    pub fn db(&self) -> &Arc<Db> {
        &self.db
    }

    /// Scopes the database to `tenant` (numeric) for the next operation.
    fn set_tenant(&self, tenant: Option<&str>) -> Result<()> {
        match tenant {
            Some(t) if !php::is_numeric(&Value::from(t)) => {
                Err(Error::invalid_argument(format!("Database adapter requires a numeric tenant ID, got: {t}")))
            }
            Some(t) => {
                self.db.set_tenant(DbValue::Int(php_std::format::str_to_int(t.as_bytes())));
                Ok(())
            }
            None => {
                self.db.set_tenant(DbValue::Null);
                Ok(())
            }
        }
    }

    fn with_type(queries: &[Query], kind: Option<Kind>) -> Vec<Query> {
        let mut out = queries.to_vec();
        if let Some(kind) = kind {
            out.push(Query::equal("type", [kind.as_str()]));
        }
        out
    }

    fn validate_group_by(queries: &[Query]) -> Result<()> {
        for query in queries {
            if query.method() != Method::GroupBy {
                continue;
            }
            let attribute = query.attribute();
            let allowed = EVENT_COLUMNS.iter().chain(GAUGE_COLUMNS.iter().filter(|c| !EVENT_COLUMNS.contains(c)));
            if !EVENT_COLUMNS.contains(&attribute) && !GAUGE_COLUMNS.contains(&attribute) {
                return Err(Error::exception(format!(
                    "Invalid groupBy attribute '{attribute}'. Allowed: {}",
                    allowed.copied().collect::<Vec<_>>().join(", ")
                )));
            }
        }
        Ok(())
    }

    fn convert(queries: &[Query]) -> Result<Vec<DbQuery>> {
        Self::validate_group_by(queries)?;
        let mut out = Vec::with_capacity(queries.len());
        for query in queries {
            let attribute = query.attribute();
            let values = query.values();
            let scalar = |v: Option<&Value>| v.cloned();
            match query.method() {
                Method::Equal => out.push(DbQuery::equal(attribute, values.clone())),
                Method::GreaterThan => {
                    if let Some(v) = scalar(first(values)) {
                        out.push(DbQuery::greater_than(attribute, v));
                    }
                }
                Method::LessThan => {
                    if let Some(v) = scalar(first(values)) {
                        out.push(DbQuery::less_than(attribute, v));
                    }
                }
                Method::Between => {
                    if values.len() >= 2 {
                        out.push(DbQuery::between(
                            attribute,
                            scalar(first(values)).unwrap_or(Value::Null),
                            scalar(second(values)).unwrap_or(Value::Null),
                        ));
                    }
                }
                Method::Contains => out.push(DbQuery::contains_string(attribute, values.clone())),
                Method::ContainsAny => out.push(DbQuery::contains_any(attribute, values.clone())),
                Method::IsNull => out.push(DbQuery::is_null(attribute)),
                Method::IsNotNull => out.push(DbQuery::is_not_null(attribute)),
                Method::NotEqual => {
                    if let Some(v) = scalar(first(values)) {
                        out.push(DbQuery::not_equal(attribute, v));
                    }
                }
                Method::NotContains => out.push(DbQuery::not_contains(attribute, values.clone())),
                Method::NotBetween => {
                    if values.len() >= 2 {
                        out.push(DbQuery::not_between(
                            attribute,
                            scalar(first(values)).unwrap_or(Value::Null),
                            scalar(second(values)).unwrap_or(Value::Null),
                        ));
                    }
                }
                Method::StartsWith => {
                    if let Some(Value::Str(s)) = first(values) {
                        out.push(DbQuery::starts_with(attribute, s.clone()));
                    }
                }
                Method::EndsWith => {
                    if let Some(Value::Str(s)) = first(values) {
                        out.push(DbQuery::ends_with(attribute, s.clone()));
                    }
                }
                Method::LessThanEqual => {
                    if let Some(v) = scalar(first(values)) {
                        out.push(DbQuery::less_than_equal(attribute, v));
                    }
                }
                Method::GreaterThanEqual => {
                    if let Some(v) = scalar(first(values)) {
                        out.push(DbQuery::greater_than_equal(attribute, v));
                    }
                }
                Method::OrderDesc => out.push(DbQuery::order_desc(attribute, None)),
                Method::OrderAsc => out.push(DbQuery::order_asc(attribute, None)),
                Method::Limit => {
                    if !values.is_empty() {
                        out.push(DbQuery::limit(first(values).map_or(0, cast_int)));
                    }
                }
                Method::Offset => {
                    if !values.is_empty() {
                        out.push(DbQuery::offset(first(values).map_or(0, cast_int)));
                    }
                }
                Method::GroupByTimeBucket | Method::GroupBy | Method::Max => {}
                Method::CursorAfter | Method::CursorBefore => {
                    return Err(Error::exception(
                        "The Database adapter does not support cursor pagination; use the ClickHouse adapter or limit/offset.",
                    ));
                }
                other => {
                    return Err(Error::exception(format!(
                        "Unsupported query method for the Database adapter: {}",
                        other.as_str()
                    )));
                }
            }
        }
        Ok(out)
    }

    async fn total_of(&self, tenant: &str, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64> {
        let mut all = queries.to_vec();
        all.push(Query::equal("metric", [metric]));

        if kind == Some(Kind::Gauge) {
            all.push(Query::order_desc("time", None));
            all.push(Query::limit(1));
            let found = self.find(tenant, &all, kind).await?;
            return Ok(found.first().map_or(0, |m| m.value(Some(Number::Int(0))).map_or(0, Number::to_int)));
        }

        let results = self.find(tenant, &all, kind).await?;
        if results.is_empty() {
            return Ok(0);
        }
        let value = |m: &Metric| m.value(Some(Number::Int(0))).map_or(0, Number::to_int);
        if kind == Some(Kind::Event) {
            return Ok(results.iter().map(value).fold(0i64, i64::wrapping_add));
        }

        let (mut gauges, events): (Vec<&Metric>, Vec<&Metric>) = results.iter().partition(|m| m.kind() == b"gauge");
        if !events.is_empty() && !gauges.is_empty() {
            return Err(Error::exception(format!(
                "Metric '{metric}' exists as both event and gauge. Specify $type explicitly to avoid ambiguous aggregation."
            )));
        }
        if !gauges.is_empty() {
            gauges.sort_by(|a, b| a.text("time").unwrap_or(b"").cmp(b.text("time").unwrap_or(b"")));
            return Ok(gauges.last().map_or(0, |m| value(m)));
        }
        Ok(events.iter().map(|m| value(m)).fold(0i64, i64::wrapping_add))
    }
}

#[async_trait]
impl Adapter for Database {
    fn name(&self) -> &str {
        "Database"
    }

    async fn health_check(&self) -> Array {
        let mut out = Array::default();
        let database = self.db.database();
        let mut set = |k: &str, v: Value| {
            out.set(ArrayKey::from(k), v);
        };
        match self.db.exists(Some(&database)).await {
            Err(e) => {
                set("healthy", Value::Bool(false));
                set("error", Value::from(e.message()));
            }
            Ok(false) => {
                set("healthy", Value::Bool(false));
                set("error", Value::from(format!("Database '{database}' does not exist").as_str()));
            }
            Ok(true) => match self.db.find_collection(COLLECTION).await {
                Err(e) => {
                    set("healthy", Value::Bool(false));
                    set("error", Value::from(e.message()));
                }
                Ok(None) => {
                    set("healthy", Value::Bool(false));
                    set("database", Value::from(database.as_str()));
                    set("collection", Value::from(COLLECTION));
                    set(
                        "error",
                        Value::from(
                            format!("Collection '{COLLECTION}' is missing or empty in database '{database}'").as_str(),
                        ),
                    );
                }
                Ok(Some(_)) => {
                    set("healthy", Value::Bool(true));
                    set("database", Value::from(database.as_str()));
                    set("collection", Value::from(COLLECTION));
                }
            },
        }
        out
    }

    async fn setup(&self) -> Result<()> {
        if !self.db.exists(Some(&self.db.database())).await? {
            return Err(Error::exception("You need to create the database before running Usage setup"));
        }
        // The event schema is a superset of the gauge schema for the
        // dimensions both have, so one collection serves both types.
        let mut attributes = attribute_documents(Kind::Event)?;
        let mut indexes = index_documents(Kind::Event)?;
        // A `type` column tells events and gauges apart in the one collection.
        attributes.push(DbAttribute::string("type", 16));
        indexes.push(DbIndex::key("index-type", ["type"], Vec::new(), Vec::new())?);

        let collection = Collection::create(COLLECTION, "", attributes, indexes, None, true, Default::default())?;
        match self.db.create_collection(collection).await {
            Ok(_) => Ok(()),
            Err(e) if e.is_a(DbKind::Duplicate) => Ok(()),
            Err(e) => Err(e.into()),
        }
    }

    async fn add_batch(&self, metrics: &[Entry], kind: Kind, batch_size: i64) -> Result<bool> {
        let authorization = self.db.authorization();
        authorization
            .skip(async {
                let mut entries = Vec::with_capacity(metrics.len());
                for metric in metrics {
                    if metric.value < 0 && !metric.allow_negative {
                        return Err(Error::invalid_argument("Value cannot be negative"));
                    }
                    let columns = Metric::extract_columns(&metric.tags, kind)?;
                    let mut doc = utopia_database::Array::default();
                    doc.set(ArrayKey::from("$id"), DbValue::from(php::random_hex(16).as_str()));
                    doc.set(ArrayKey::from("$permissions"), DbValue::Array(utopia_database::Array::default()));
                    doc.set(ArrayKey::from("metric"), DbValue::from(metric.metric.as_str()));
                    doc.set(ArrayKey::from("value"), DbValue::Int(metric.value));
                    doc.set(ArrayKey::from("type"), DbValue::from(kind.as_str()));
                    let now = php::datetime(b"now")?;
                    doc.set(ArrayKey::from("time"), DbValue::from(php::format(&now, "Y-m-d H:i:s.v").as_str()));
                    for (column, value) in columns {
                        doc.set(ArrayKey::from(column), value.map_or(DbValue::Null, DbValue::Str));
                    }
                    entries.push((metric.tenant.as_deref(), Document::new(doc)?));
                }
                for chunk in entries.chunks(batch_size.max(1) as usize) {
                    for (tenant, doc) in chunk {
                        self.set_tenant(*tenant)?;
                        self.db.create_document(COLLECTION, doc.clone()).await?;
                    }
                }
                Ok(())
            })
            .await?;
        Ok(true)
    }

    async fn time_series(
        &self,
        _tenant: &str,
        metrics: &[String],
        _interval: &str,
        _start: &str,
        _end: &str,
        _queries: &[Query],
        _zero_fill: bool,
        _kind: Option<Kind>,
    ) -> Result<TimeSeries> {
        Ok(metrics.iter().map(|m| (m.clone(), Series { total: Number::Int(0), data: Vec::new() })).collect())
    }

    async fn total(&self, tenant: &str, metric: &str, queries: &[Query], kind: Option<Kind>) -> Result<i64> {
        self.total_of(tenant, metric, queries, kind).await
    }

    async fn total_batch(
        &self,
        tenant: &str,
        metrics: &[String],
        queries: &[Query],
        kind: Option<Kind>,
    ) -> Result<Totals> {
        let mut totals: Totals = metrics.iter().map(|m| (m.clone(), 0)).collect();
        for metric in metrics {
            let total = self.total_of(tenant, metric, queries, kind).await?;
            totals.insert(metric.clone(), total);
        }
        Ok(totals)
    }

    async fn purge(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<bool> {
        self.set_tenant(Some(tenant))?;
        let queries = Self::with_type(queries, kind);
        self.db
            .authorization()
            .skip(async {
                let mut converted = Self::convert(&queries)?;
                converted.push(DbQuery::limit(100));
                loop {
                    let documents = self.db.find(COLLECTION, converted.clone(), PermissionType::Read).await?;
                    if documents.is_empty() {
                        return Ok::<(), Error>(());
                    }
                    for document in documents {
                        self.db.delete_document(COLLECTION, document.id()).await?;
                    }
                }
            })
            .await?;
        Ok(true)
    }

    async fn find(&self, tenant: &str, queries: &[Query], kind: Option<Kind>) -> Result<Vec<Metric>> {
        self.set_tenant(Some(tenant))?;
        let queries = Self::with_type(queries, kind);
        let documents = self
            .db
            .authorization()
            .skip(async {
                let converted = Self::convert(&queries)?;
                Ok::<_, Error>(self.db.find(COLLECTION, converted, PermissionType::Read).await?)
            })
            .await?;
        Ok(documents.iter().map(|d| Metric::new(to_query_array(&d.array_copy()))).collect())
    }

    async fn count(&self, tenant: &str, queries: &[Query], kind: Option<Kind>, max: Option<i64>) -> Result<i64> {
        self.set_tenant(Some(tenant))?;
        let queries = Self::with_type(queries, kind);
        self.db
            .authorization()
            .skip(async {
                let converted = Self::convert(&queries)?;
                Ok(self.db.count(COLLECTION, converted, max).await?)
            })
            .await
    }

    async fn sum(&self, tenant: &str, queries: &[Query], _attribute: &str, kind: Kind) -> Result<i64> {
        let results = self.find(tenant, queries, Some(kind)).await?;
        Ok(results
            .iter()
            .map(|m| m.value(Some(Number::Int(0))).map_or(0, Number::to_int))
            .fold(0i64, i64::wrapping_add))
    }

    async fn find_daily(&self, tenant: &str, queries: &[Query]) -> Result<Vec<Metric>> {
        self.find(tenant, queries, Some(Kind::Event)).await
    }

    async fn sum_daily(&self, tenant: &str, queries: &[Query], attribute: &str) -> Result<i64> {
        self.sum(tenant, queries, attribute, Kind::Event).await
    }

    async fn sum_daily_batch(&self, tenant: &str, metrics: &[String], queries: &[Query]) -> Result<Totals> {
        let mut totals: Totals = metrics.iter().map(|m| (m.clone(), 0)).collect();
        for metric in metrics {
            let mut per = queries.to_vec();
            per.push(Query::equal("metric", [metric.as_str()]));
            let total = self.sum_daily(tenant, &per, "value").await?;
            totals.insert(metric.clone(), total);
        }
        Ok(totals)
    }

    async fn backfill_daily(&self, _from: &str, _to: &str, _force: bool) -> Result<()> {
        Err(Error::exception("Utopia\\Usage\\Adapter\\Database does not maintain a daily rollup to backfill."))
    }
}
