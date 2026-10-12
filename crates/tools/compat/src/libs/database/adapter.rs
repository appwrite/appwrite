//! adapter.*: the SQL adapters called directly (`Utopia\Database\Adapter\Postgres`,
//! and with `driver` `mariadb`/`mysql` `Adapter\MariaDB`/`Adapter\MySQL`).
//! Twin of `tests/compat/database/ops/adapter.php`.

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use serde_json::{Map, Value as Json, json};
use utopia_database::adapter::mariadb::{Flavor, MariaDB};
use utopia_database::adapter::postgres::Postgres;
use utopia_database::adapter::postgres::pdo::PdoError;
use utopia_database::adapter::{Adapter, FindOptions, with_transaction};
use utopia_database::document::Document;
use utopia_database::value::{Array, ArrayKey, Str, Value};
use utopia_database::{Authorization, Change, Error, Kind, Query};

use super::codec;
use crate::adapter::{Fault, OpResult, Outcome, Session};

/// Operations of this area (must match `spec.d/` and `ops/adapter.php`).
pub const OPS: &[&str] = &[
    "adapter.open",
    "adapter.set_authorization",
    "adapter.get_authorization",
    "adapter.set_database",
    "adapter.get_database",
    "adapter.set_namespace",
    "adapter.get_namespace",
    "adapter.set_shared_tables",
    "adapter.get_shared_tables",
    "adapter.set_tenant",
    "adapter.get_tenant",
    "adapter.set_tenant_per_document",
    "adapter.get_tenant_per_document",
    "adapter.set_hostname",
    "adapter.get_hostname",
    "adapter.set_debug",
    "adapter.get_debug",
    "adapter.reset_debug",
    "adapter.set_metadata",
    "adapter.get_metadata",
    "adapter.reset_metadata",
    "adapter.set_timeout",
    "adapter.get_timeout",
    "adapter.clear_timeout",
    "adapter.clear_timeouts",
    "adapter.before",
    "adapter.captured",
    "adapter.filter",
    "adapter.enable_alter_locks",
    "adapter.lock_type",
    "adapter.column_type",
    "adapter.tenant_query",
    "adapter.sql_conditions",
    "adapter.set_float_precision",
    "adapter.driver_exec",
    "adapter.driver_query",
    "adapter.process_exception",
    "adapter.in_transaction",
    "adapter.start_transaction",
    "adapter.commit_transaction",
    "adapter.rollback_transaction",
    "adapter.with_transaction",
    "adapter.ping",
    "adapter.reconnect",
    "adapter.connection_id",
    "adapter.create",
    "adapter.exists",
    "adapter.list",
    "adapter.delete",
    "adapter.create_collection",
    "adapter.delete_collection",
    "adapter.analyze_collection",
    "adapter.size_of_collection",
    "adapter.size_of_collection_on_disk",
    "adapter.schema_attributes",
    "adapter.schema_indexes",
    "adapter.create_attribute",
    "adapter.create_attributes",
    "adapter.update_attribute",
    "adapter.delete_attribute",
    "adapter.rename_attribute",
    "adapter.create_relationship",
    "adapter.update_relationship",
    "adapter.delete_relationship",
    "adapter.create_index",
    "adapter.delete_index",
    "adapter.rename_index",
    "adapter.get_document",
    "adapter.create_document",
    "adapter.create_documents",
    "adapter.update_document",
    "adapter.update_documents",
    "adapter.upsert_documents",
    "adapter.get_sequences",
    "adapter.delete_document",
    "adapter.delete_documents",
    "adapter.increase_document_attribute",
    "adapter.find",
    "adapter.count",
    "adapter.sum",
    "adapter.casting_before",
    "adapter.casting_after",
    "adapter.set_utc_datetime",
    "adapter.decode_point",
    "adapter.decode_linestring",
    "adapter.decode_polygon",
    "adapter.spatial_type",
    "adapter.limits",
    "adapter.counts",
    "adapter.capabilities",
    "adapter.fuzz_find",
    "adapter.fuzz_roundtrip",
];

/// What the operations call besides the [`Adapter`] trait: the methods of
/// PHP's `SQL` class (and the drivers' handles) each SQL adapter has.
#[async_trait]
trait SqlAdapter: Adapter {
    fn lock_type(&self) -> &'static str;
    fn tenant_query_full(&self, collection: &str, alias: &str, tenant_count: usize, condition: &str) -> String;
    fn sql_conditions(
        &self,
        queries: &[Query],
        binds: &mut Vec<(String, Value)>,
        separator: &str,
    ) -> utopia_database::Result<String>;
    fn set_float_precision(&self, precision: i64);
    async fn driver_exec(&self, sql: &str) -> utopia_database::Result<i64>;
    async fn driver_query(&self, sql: &str) -> utopia_database::Result<Vec<Array>>;
    fn spatial_type_from_wkt(&self, wkt: &[u8]) -> utopia_database::Result<Vec<u8>>;
    fn try_attribute_width(&self, collection: &Document) -> utopia_database::Result<i64>;
    fn support_for_numeric_casting(&self) -> bool;
    fn support_for_json_overlaps(&self) -> bool;
    fn like_operator(&self) -> &'static str;
    fn regex_operator(&self) -> &'static str;
}

macro_rules! sql_adapter {
    ($t:ty) => {
        #[async_trait]
        impl SqlAdapter for $t {
            fn lock_type(&self) -> &'static str {
                <$t>::lock_type(self)
            }
            fn tenant_query_full(&self, collection: &str, alias: &str, tenant_count: usize, condition: &str) -> String {
                <$t>::tenant_query_full(self, collection, alias, tenant_count, condition)
            }
            fn sql_conditions(
                &self,
                queries: &[Query],
                binds: &mut Vec<(String, Value)>,
                separator: &str,
            ) -> utopia_database::Result<String> {
                <$t>::sql_conditions(self, queries, binds, separator)
            }
            fn set_float_precision(&self, precision: i64) {
                <$t>::set_float_precision(self, precision)
            }
            async fn driver_exec(&self, sql: &str) -> utopia_database::Result<i64> {
                <$t>::driver_exec(self, sql).await
            }
            async fn driver_query(&self, sql: &str) -> utopia_database::Result<Vec<Array>> {
                <$t>::driver_query(self, sql).await
            }
            fn spatial_type_from_wkt(&self, wkt: &[u8]) -> utopia_database::Result<Vec<u8>> {
                <$t>::spatial_type_from_wkt(self, wkt)
            }
            fn try_attribute_width(&self, collection: &Document) -> utopia_database::Result<i64> {
                <$t>::try_attribute_width(self, collection)
            }
            fn support_for_numeric_casting(&self) -> bool {
                <$t>::support_for_numeric_casting(self)
            }
            fn support_for_json_overlaps(&self) -> bool {
                <$t>::support_for_json_overlaps(self)
            }
            fn like_operator(&self) -> &'static str {
                <$t>::like_operator(self)
            }
            fn regex_operator(&self) -> &'static str {
                <$t>::regex_operator(self)
            }
        }
    };
}

sql_adapter!(Postgres);
sql_adapter!(MariaDB);

/// The adapter `driver` names: `postgres` (default), `mariadb` or `mysql`,
/// on its service.
async fn connect(driver: &str, session: &Session) -> Result<utopia_database::Result<Arc<dyn SqlAdapter>>, Fault> {
    Ok(match driver {
        "postgres" => {
            let url = session.service("database-postgres")?.to_owned();
            Postgres::connect(&url).await.map(|db| Arc::new(db) as Arc<dyn SqlAdapter>)
        }
        "mariadb" => {
            let url = session.service("database-mariadb")?.to_owned();
            MariaDB::connect_as(&url, Flavor::MariaDB).await.map(|db| Arc::new(db) as Arc<dyn SqlAdapter>)
        }
        "mysql" => {
            let url = session.service("database-mysql")?.to_owned();
            MariaDB::connect_as(&url, Flavor::MySQL).await.map(|db| Arc::new(db) as Arc<dyn SqlAdapter>)
        }
        other => return Err(Fault::new(format!("adapter: unknown driver `{other}`"))),
    })
}

/// An adapter and the statements its `before` transforms captured.
struct Handle {
    db: Arc<dyn SqlAdapter>,
    captured: Arc<Mutex<Vec<(String, String)>>>,
}

/// `preg_replace('/[0-9a-f]{20}/', '<id>', $sql)`: hides `ID::unique()`.
fn normalize(sql: &str) -> String {
    let b = sql.as_bytes();
    let hex = |c: u8| c.is_ascii_digit() || (b'a'..=b'f').contains(&c);
    let mut out = String::with_capacity(sql.len());
    let mut i = 0;
    let mut last = 0;
    while i + 20 <= b.len() {
        if b[i..i + 20].iter().all(|c| hex(*c)) {
            out.push_str(&sql[last..i]);
            out.push_str("<id>");
            i += 20;
            last = i;
        } else {
            i += 1;
        }
    }
    out.push_str(&sql[last..]);
    out
}

fn value(args: &Json, key: &str) -> Result<Value, Fault> {
    match args.get(key) {
        None => Ok(Value::Null),
        Some(j) => codec::decode(j),
    }
}

/// `(string) $a[$key]` (`""` when absent).
fn text(args: &Json, key: &str) -> Result<String, Fault> {
    Ok(utopia_database::value::to_text(&value(args, key)?))
}

fn text_or(args: &Json, key: &str, default: &str) -> Result<String, Fault> {
    match args.get(key) {
        None | Some(Json::Null) => Ok(default.to_owned()),
        _ => text(args, key),
    }
}

fn opt_text(args: &Json, key: &str) -> Result<Option<String>, Fault> {
    match args.get(key) {
        None | Some(Json::Null) => Ok(None),
        _ => text(args, key).map(Some),
    }
}

/// `(int) $a[$key]`.
fn int_or(args: &Json, key: &str, default: i64) -> Result<i64, Fault> {
    match args.get(key) {
        None | Some(Json::Null) => Ok(default),
        _ => Ok(to_long(&value(args, key)?)),
    }
}

fn to_long(v: &Value) -> i64 {
    utopia_database::adapter::postgres::pdo::to_long(v)
}

/// `(bool) ($a[$key] ?? $default)`.
fn flag(args: &Json, key: &str, default: bool) -> Result<bool, Fault> {
    match args.get(key) {
        None | Some(Json::Null) => Ok(default),
        _ => Ok(value(args, key)?.truthy()),
    }
}

fn strings(args: &Json, key: &str) -> Result<Vec<String>, Fault> {
    codec::list(args.get(key).unwrap_or(&Json::Null))?
        .into_iter()
        .map(|j| codec::decode(j).map(|v| utopia_database::value::to_text(&v)))
        .collect()
}

fn values(args: &Json, key: &str) -> Result<Vec<Value>, Fault> {
    match value(args, key)? {
        Value::Array(a) => Ok(a.values().cloned().collect()),
        Value::Null | Value::Object(_) => Ok(Vec::new()),
        other => Err(Fault::new(format!("expected a list, got {}", other.type_name()))),
    }
}

/// A collection argument: an id, or a `$document`.
fn collection(args: &Json) -> Result<Document, Fault> {
    match value(args, "collection")? {
        Value::Ext(utopia_database::value::Ext::Document(d)) => Ok(*d),
        other => Ok(Document::from_array_unchecked(
            [(ArrayKey::from("$id"), Value::Str(Str::from(utopia_database::value::to_text(&other))))]
                .into_iter()
                .collect(),
        )),
    }
}

fn document(args: &Json, key: &str) -> Result<Document, Fault> {
    codec::document(args.get(key).unwrap_or(&Json::Null))
}

fn documents(args: &Json, key: &str) -> Result<Vec<Document>, Fault> {
    codec::documents(args.get(key).unwrap_or(&Json::Null))
}

fn ok(v: Value) -> OpResult {
    Ok(Outcome::Ok(codec::encode(&v)))
}

fn res<T>(r: utopia_database::Result<T>, f: impl FnOnce(T) -> Json) -> OpResult {
    Ok(match r {
        Ok(v) => Outcome::Ok(f(v)),
        Err(e) => codec::error(&e),
    })
}

fn unit<T>(r: utopia_database::Result<T>) -> OpResult {
    res(r, |_| Json::Null)
}

fn boolean(r: utopia_database::Result<bool>) -> OpResult {
    res(r, Json::Bool)
}

fn rows(rows: Vec<Array>) -> Json {
    Json::Array(rows.into_iter().map(|r| codec::encode(&Value::Array(r))).collect())
}

pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    if op == "adapter.open" {
        let db = match connect(&text_or(args, "driver", "postgres")?, session).await? {
            Ok(db) => db,
            Err(e) => return Ok(codec::error(&e)),
        };
        db.set_authorization(Arc::new(Authorization::new()));
        let database = text_or(args, "database", &session.ns)?;
        if let Err(e) = db.set_database(&database) {
            return Ok(codec::error(&e));
        }
        if let Err(e) = db.set_namespace(&text_or(args, "namespace", "ns")?) {
            return Ok(codec::error(&e));
        }
        if args.get("shared_tables").is_some() {
            db.set_shared_tables(flag(args, "shared_tables", false)?);
        }
        if args.get("tenant").is_some() {
            db.set_tenant(value(args, "tenant")?);
        }
        if args.get("tenant_per_document").is_some() {
            db.set_tenant_per_document(flag(args, "tenant_per_document", false)?);
        }
        return Ok(Outcome::Ok(session.handle(Handle { db, captured: Arc::new(Mutex::new(Vec::new())) })));
    }
    if op == "adapter.process_exception" {
        let native = match args.get("native") {
            None | Some(Json::Null) => None,
            Some(n) => n.as_i64(),
        };
        let (sqlstate, message) = (text(args, "sqlstate")?, text(args, "message")?);
        let driver = text_or(args, "driver", "postgres")?;
        let error = match driver.as_str() {
            "postgres" => utopia_database::adapter::postgres::process_exception(PdoError { sqlstate, native, message }),
            "mariadb" | "mysql" => {
                let e = utopia_database::adapter::mariadb::pdo::PdoError {
                    sqlstate,
                    native,
                    info: message.clone(),
                    message,
                };
                utopia_database::adapter::mariadb::process_exception(e, driver == "mysql")
            }
            other => return Err(Fault::new(format!("adapter: unknown driver `{other}`"))),
        };
        return Ok(codec::error(&error));
    }
    if op == "adapter.fuzz_find" || op == "adapter.fuzz_roundtrip" {
        let driver = text_or(args, "driver", "postgres")?;
        let db = match fuzz_adapter(session, &driver).await? {
            Ok(db) => db,
            Err(e) => return Ok(codec::error(&e)),
        };
        let db = db.as_ref();
        return if op == "adapter.fuzz_find" {
            fuzz_find(db, args).await
        } else {
            fuzz_roundtrip(db, args, driver == "postgres").await
        };
    }

    let ns = session.ns.clone();
    let h = session.get::<Handle>(args.get("adapter").unwrap_or(&Json::Null))?;
    let db: &dyn SqlAdapter = h.db.as_ref();
    match op {
        "adapter.set_authorization" => {
            let authorization = Authorization::new();
            if args.get("roles").is_some() {
                authorization.clean_roles();
                for role in strings(args, "roles")? {
                    authorization.add_role(&role);
                }
            }
            if args.get("status").is_some() {
                authorization.set_status(flag(args, "status", true)?);
            }
            db.set_authorization(Arc::new(authorization));
            ok(Value::Null)
        }
        "adapter.get_authorization" => {
            let a = db.authorization();
            Ok(Outcome::Ok(json!({
                "roles": Json::Array(a.roles().iter().map(codec::encode).collect()),
                "status": a.status(),
            })))
        }
        "adapter.set_database" => boolean(db.set_database(&text(args, "name")?)),
        "adapter.get_database" => ok(Value::Str(db.database().into())),
        "adapter.set_namespace" => unit(db.set_namespace(&text(args, "namespace")?)),
        "adapter.get_namespace" => ok(Value::Str(db.namespace().into())),
        "adapter.set_shared_tables" => ok(Value::Bool(db.set_shared_tables(flag(args, "shared", false)?))),
        "adapter.get_shared_tables" => ok(Value::Bool(db.shared_tables())),
        "adapter.set_tenant" => ok(Value::Bool(db.set_tenant(value(args, "tenant")?))),
        "adapter.get_tenant" => ok(db.tenant()),
        "adapter.set_tenant_per_document" => ok(Value::Bool(db.set_tenant_per_document(flag(args, "enabled", false)?))),
        "adapter.get_tenant_per_document" => ok(Value::Bool(db.tenant_per_document())),
        "adapter.set_hostname" => {
            db.set_hostname(&text(args, "hostname")?);
            ok(Value::Null)
        }
        "adapter.get_hostname" => ok(Value::Str(db.hostname().into())),
        "adapter.set_debug" => {
            db.set_debug(&text(args, "key")?, value(args, "value")?);
            ok(Value::Null)
        }
        "adapter.get_debug" => ok(Value::Array(db.debug())),
        "adapter.reset_debug" => {
            db.reset_debug();
            ok(Value::Null)
        }
        "adapter.set_metadata" => {
            db.set_metadata(&text(args, "key")?, &text(args, "value")?);
            ok(Value::Null)
        }
        "adapter.get_metadata" => {
            let mut m = Map::new();
            for (k, v) in db.metadata() {
                m.insert(k, Json::String(v));
            }
            Ok(Outcome::Ok(if m.is_empty() { Json::Array(Vec::new()) } else { Json::Object(m) }))
        }
        "adapter.reset_metadata" => {
            db.reset_metadata();
            ok(Value::Null)
        }
        "adapter.set_timeout" => unit(db.set_timeout(int_or(args, "milliseconds", 0)?, &text_or(args, "event", "*")?)),
        "adapter.get_timeout" => ok(Value::Int(db.timeout())),
        "adapter.clear_timeout" => {
            db.clear_timeout(&text(args, "event")?);
            ok(Value::Null)
        }
        "adapter.clear_timeouts" => {
            db.clear_timeouts();
            ok(Value::Null)
        }
        "adapter.before" => {
            let event = text(args, "event")?;
            let name = text_or(args, "name", "")?;
            if flag(args, "remove", false)? {
                db.before(&event, &name, None);
                return ok(Value::Null);
            }
            let prepend = opt_text(args, "prepend")?;
            let captured = h.captured.clone();
            let e = event.clone();
            db.before(
                &event,
                &name,
                Some(Arc::new(move |query: String| {
                    captured.lock().unwrap_or_else(|p| p.into_inner()).push((e.clone(), normalize(&query)));
                    match &prepend {
                        Some(p) => format!("{p}{query}"),
                        None => query,
                    }
                })),
            );
            ok(Value::Null)
        }
        "adapter.captured" => {
            let list = std::mem::take(&mut *h.captured.lock().unwrap_or_else(|p| p.into_inner()));
            Ok(Outcome::Ok(Json::Array(list.into_iter().map(|(e, q)| json!([e, q])).collect())))
        }
        "adapter.filter" => res(db.filter(&text(args, "value")?), Json::String),
        "adapter.enable_alter_locks" => {
            db.enable_alter_locks(flag(args, "enable", false)?);
            ok(Value::Null)
        }
        "adapter.lock_type" => ok(Value::Str(db.lock_type().into())),
        "adapter.column_type" => res(
            db.column_type(
                &text(args, "type")?,
                int_or(args, "size", 0)?,
                flag(args, "signed", true)?,
                flag(args, "array", false)?,
                flag(args, "required", false)?,
            ),
            Json::String,
        ),
        "adapter.tenant_query" => ok(Value::Str(
            db.tenant_query_full(
                &text(args, "collection")?,
                &text_or(args, "alias", "")?,
                int_or(args, "tenant_count", 0)?.max(0) as usize,
                &text_or(args, "condition", "AND")?,
            )
            .into(),
        )),
        "adapter.sql_conditions" => {
            let queries = match typed_queries(args)? {
                Ok(q) => q,
                Err(e) => return Ok(codec::error(&e)),
            };
            let mut binds = Vec::new();
            res(db.sql_conditions(&queries, &mut binds, &text_or(args, "separator", "AND")?), |sql| {
                json!({
                    "sql": normalize(&sql),
                    "binds": codec::encode(&Value::Array(binds.into_iter().map(|(_, v)| v).collect())),
                })
            })
        }
        "adapter.set_float_precision" => {
            db.set_float_precision(int_or(args, "precision", 17)?);
            ok(Value::Null)
        }
        "adapter.driver_exec" => res(db.driver_exec(&text(args, "sql")?).await, Json::from),
        "adapter.driver_query" => res(db.driver_query(&text(args, "sql")?).await, rows),
        "adapter.in_transaction" => ok(Value::Bool(db.in_transaction())),
        "adapter.start_transaction" => boolean(db.start_transaction().await),
        "adapter.commit_transaction" => boolean(db.commit_transaction().await),
        "adapter.rollback_transaction" => boolean(db.rollback_transaction().await),
        "adapter.with_transaction" => {
            let calls = AtomicUsize::new(0);
            let c = collection(args)?;
            let d = document(args, "document")?;
            let fail = opt_text(args, "fail")?;
            let fail_times = int_or(args, "fail_times", i64::MAX)?;
            let result = with_transaction(db, || {
                let n = calls.fetch_add(1, Ordering::SeqCst) as i64 + 1;
                let (c, d, fail) = (c.clone(), d.clone(), fail.clone());
                async move {
                    let doc = db.create_document(&c, d).await?;
                    if let Some(f) = fail
                        && fail_times >= n
                    {
                        let kind = match f.as_str() {
                            "duplicate" => Kind::Duplicate,
                            "timeout" => Kind::Timeout,
                            _ => Kind::Database,
                        };
                        return Err(Error::new(kind, "callback failed"));
                    }
                    Ok(doc)
                }
            })
            .await;
            let calls = calls.load(Ordering::SeqCst);
            Ok(Outcome::Ok(match result {
                Ok(doc) => json!({"calls": calls, "result": codec::encode_document(&doc)}),
                Err(e) => json!({"calls": calls, "error": {"class": e.php_class(), "message": e.message()}}),
            }))
        }
        "adapter.ping" => boolean(db.ping().await),
        "adapter.reconnect" => unit(db.reconnect().await),
        "adapter.connection_id" => res(db.connection_id().await, Json::String),
        "adapter.create" => boolean(db.create(&text_or(args, "name", &ns)?).await),
        "adapter.exists" => {
            let database = text_or(args, "database", &ns)?;
            let c = opt_text(args, "collection")?;
            boolean(db.exists(&database, c.as_deref()).await)
        }
        "adapter.list" => res(db.list().await, |d| codec::encode_documents(&d)),
        "adapter.delete" => boolean(db.delete(&text_or(args, "name", &ns)?).await),
        "adapter.create_collection" => boolean(
            db.create_collection(&text(args, "name")?, &documents(args, "attributes")?, &documents(args, "indexes")?)
                .await,
        ),
        "adapter.delete_collection" => boolean(db.delete_collection(&text(args, "id")?).await),
        "adapter.analyze_collection" => boolean(db.analyze_collection(&text(args, "collection")?).await),
        "adapter.size_of_collection" => res(db.size_of_collection(&text(args, "collection")?).await, Json::from),
        "adapter.size_of_collection_on_disk" => {
            res(db.size_of_collection_on_disk(&text(args, "collection")?).await, Json::from)
        }
        "adapter.schema_attributes" => {
            res(db.schema_attributes(&text(args, "collection")?).await, |d| codec::encode_documents(&d))
        }
        "adapter.schema_indexes" => {
            res(db.schema_indexes(&text(args, "collection")?).await, |d| codec::encode_documents(&d))
        }
        "adapter.create_attribute" => boolean(
            db.create_attribute(
                &text(args, "collection")?,
                &text(args, "id")?,
                &text(args, "type")?,
                int_or(args, "size", 0)?,
                flag(args, "signed", true)?,
                flag(args, "array", false)?,
                flag(args, "required", false)?,
            )
            .await,
        ),
        "adapter.create_attributes" => {
            let attributes: Vec<Array> = values(args, "attributes")?
                .into_iter()
                .map(|v| match v {
                    Value::Array(a) => Ok(a),
                    Value::Ext(utopia_database::value::Ext::Document(d)) => Ok(d.into_array()),
                    other => Err(Fault::new(format!("expected an array, got {}", other.type_name()))),
                })
                .collect::<Result<_, _>>()?;
            boolean(db.create_attributes(&text(args, "collection")?, &attributes).await)
        }
        "adapter.update_attribute" => boolean(
            db.update_attribute(
                &text(args, "collection")?,
                &text(args, "id")?,
                &text(args, "type")?,
                int_or(args, "size", 0)?,
                flag(args, "signed", true)?,
                flag(args, "array", false)?,
                opt_text(args, "new_key")?.as_deref(),
                flag(args, "required", false)?,
            )
            .await,
        ),
        "adapter.delete_attribute" => {
            boolean(db.delete_attribute(&text(args, "collection")?, &text(args, "id")?).await)
        }
        "adapter.rename_attribute" => {
            boolean(db.rename_attribute(&text(args, "collection")?, &text(args, "old")?, &text(args, "new")?).await)
        }
        "adapter.create_relationship" => boolean(
            db.create_relationship(
                &text(args, "collection")?,
                &text(args, "related_collection")?,
                &text(args, "type")?,
                flag(args, "two_way", false)?,
                &text_or(args, "id", "")?,
                &text_or(args, "two_way_key", "")?,
            )
            .await,
        ),
        "adapter.update_relationship" => boolean(
            db.update_relationship(
                &text(args, "collection")?,
                &text(args, "related_collection")?,
                &text(args, "type")?,
                flag(args, "two_way", false)?,
                &text_or(args, "key", "")?,
                &text_or(args, "two_way_key", "")?,
                &text_or(args, "side", "parent")?,
                opt_text(args, "new_key")?.as_deref(),
                opt_text(args, "new_two_way_key")?.as_deref(),
            )
            .await,
        ),
        "adapter.delete_relationship" => boolean(
            db.delete_relationship(
                &text(args, "collection")?,
                &text(args, "related_collection")?,
                &text(args, "type")?,
                flag(args, "two_way", false)?,
                &text_or(args, "key", "")?,
                &text_or(args, "two_way_key", "")?,
                &text_or(args, "side", "parent")?,
            )
            .await,
        ),
        "adapter.create_index" => {
            let types: Vec<(String, String)> = match value(args, "attribute_types")? {
                Value::Array(a) => a
                    .iter()
                    .map(|(k, v)| {
                        let key = match k {
                            utopia_database::value::KeyRef::Int(i) => i.to_string(),
                            utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                        };
                        (key, utopia_database::value::to_text(v))
                    })
                    .collect(),
                _ => Vec::new(),
            };
            boolean(
                db.create_index(
                    &text(args, "collection")?,
                    &text(args, "id")?,
                    &text(args, "type")?,
                    &strings(args, "attributes")?,
                    &values(args, "lengths")?,
                    &values(args, "orders")?,
                    &types,
                    &strings(args, "collation")?,
                    int_or(args, "ttl", 1)?,
                )
                .await,
            )
        }
        "adapter.delete_index" => boolean(db.delete_index(&text(args, "collection")?, &text(args, "id")?).await),
        "adapter.rename_index" => {
            boolean(db.rename_index(&text(args, "collection")?, &text(args, "old")?, &text(args, "new")?).await)
        }
        "adapter.get_document" => {
            let queries = match codec::queries(args.get("queries").unwrap_or(&Json::Null))? {
                Ok(q) => q,
                Err(e) => return Ok(codec::error(&e)),
            };
            res(
                db.get_document(&collection(args)?, &text(args, "id")?, &queries, flag(args, "for_update", false)?)
                    .await,
                |d| codec::encode_document(&d),
            )
        }
        "adapter.create_document" => {
            res(db.create_document(&collection(args)?, document(args, "document")?).await, |d| {
                codec::encode_document(&d)
            })
        }
        "adapter.create_documents" => {
            let skip = flag(args, "skip_duplicates", false)?;
            let previous = if skip { Some(db.set_skip_duplicates(true)) } else { None };
            let r = db.create_documents(&collection(args)?, documents(args, "documents")?).await;
            if let Some(p) = previous {
                db.set_skip_duplicates(p);
            }
            res(r, |d| codec::encode_documents(&d))
        }
        "adapter.update_document" => res(
            db.update_document(
                &collection(args)?,
                &text(args, "id")?,
                document(args, "document")?,
                flag(args, "skip_permissions", false)?,
            )
            .await,
            |d| codec::encode_document(&d),
        ),
        "adapter.update_documents" => res(
            db.update_documents(&collection(args)?, &document(args, "updates")?, &documents(args, "documents")?).await,
            Json::from,
        ),
        "adapter.upsert_documents" => {
            let mut changes = Vec::new();
            for c in codec::list(args.get("changes").unwrap_or(&Json::Null))? {
                let old = codec::document(c.get("old").unwrap_or(&Json::Null))?;
                let new = codec::document(c.get("new").unwrap_or(&Json::Null))?;
                changes.push(Change::new(old, new));
            }
            res(db.upsert_documents(&collection(args)?, &text_or(args, "attribute", "")?, changes).await, |d| {
                codec::encode_documents(&d)
            })
        }
        "adapter.get_sequences" => {
            res(db.get_sequences(&text(args, "collection")?, documents(args, "documents")?).await, |d| {
                codec::encode_documents(&d)
            })
        }
        "adapter.delete_document" => boolean(db.delete_document(&text(args, "collection")?, &text(args, "id")?).await),
        "adapter.delete_documents" => res(
            db.delete_documents(
                &text(args, "collection")?,
                &values(args, "sequences")?,
                &strings(args, "permission_ids")?,
            )
            .await,
            Json::from,
        ),
        "adapter.increase_document_attribute" => {
            let min = match args.get("min") {
                None | Some(Json::Null) => None,
                _ => Some(value(args, "min")?),
            };
            let max = match args.get("max") {
                None | Some(Json::Null) => None,
                _ => Some(value(args, "max")?),
            };
            boolean(
                db.increase_document_attribute(
                    &text(args, "collection")?,
                    &text(args, "id")?,
                    &text(args, "attribute")?,
                    &value(args, "value")?,
                    &text(args, "updated_at")?,
                    min.as_ref(),
                    max.as_ref(),
                )
                .await,
            )
        }
        "adapter.find" => {
            let queries = match typed_queries(args)? {
                Ok(q) => q,
                Err(e) => return Ok(codec::error(&e)),
            };
            let limit = match args.get("limit") {
                None => Some(25),
                Some(Json::Null) => None,
                Some(_) => Some(int_or(args, "limit", 25)?),
            };
            let offset = match args.get("offset") {
                None | Some(Json::Null) => None,
                Some(_) => Some(int_or(args, "offset", 0)?),
            };
            let order_attributes = strings(args, "order_attributes")?;
            let order_types = strings(args, "order_types")?;
            let cursor = match args.get("cursor") {
                None | Some(Json::Null) => Array::new(),
                Some(j) => codec::decode_array(j)?,
            };
            let direction = text_or(args, "cursor_direction", "after")?;
            let permission = text_or(args, "for_permission", "read")?;
            let options = FindOptions {
                limit,
                offset,
                order_attributes: &order_attributes,
                order_types: &order_types,
                cursor: &cursor,
                cursor_direction: &direction,
                for_permission: &permission,
            };
            res(db.find(&collection(args)?, &queries, options).await, |d| codec::encode_documents(&d))
        }
        "adapter.count" => {
            let queries = match typed_queries(args)? {
                Ok(q) => q,
                Err(e) => return Ok(codec::error(&e)),
            };
            let max = match args.get("max") {
                None | Some(Json::Null) => None,
                Some(_) => Some(int_or(args, "max", 0)?),
            };
            res(db.count(&collection(args)?, &queries, max).await, Json::from)
        }
        "adapter.sum" => {
            let queries = match typed_queries(args)? {
                Ok(q) => q,
                Err(e) => return Ok(codec::error(&e)),
            };
            let max = match args.get("max") {
                None | Some(Json::Null) => None,
                Some(_) => Some(int_or(args, "max", 0)?),
            };
            res(db.sum(&collection(args)?, &text(args, "attribute")?, &queries, max).await, |v| codec::encode(&v))
        }
        "adapter.casting_before" => {
            res(db.casting_before(&collection(args)?, document(args, "document")?), |d| codec::encode_document(&d))
        }
        "adapter.casting_after" => {
            res(db.casting_after(&collection(args)?, document(args, "document")?), |d| codec::encode_document(&d))
        }
        "adapter.set_utc_datetime" => res(db.set_utc_datetime(&text(args, "value")?), |v| codec::encode(&v)),
        "adapter.decode_point" => res(db.decode_point(&bytes(args, "wkb")?), |v| codec::encode(&v)),
        "adapter.decode_linestring" => res(db.decode_linestring(&bytes(args, "wkb")?), |v| codec::encode(&v)),
        "adapter.decode_polygon" => res(db.decode_polygon(&bytes(args, "wkb")?), |v| codec::encode(&v)),
        "adapter.spatial_type" => {
            res(db.spatial_type_from_wkt(&bytes(args, "wkt")?), |v| codec::encode(&Value::Str(Str::from(v))))
        }
        "adapter.limits" => {
            let format =
                |d: php_std::datetime::DateTime| String::from_utf8_lossy(&d.format(b"Y-m-d H:i:s.v P")).into_owned();
            Ok(Outcome::Ok(json!({
                "limitForString": db.limit_for_string(),
                "limitForInt": db.limit_for_int(),
                "limitForBigInt": db.limit_for_big_int(),
                "limitForAttributes": db.limit_for_attributes(),
                "limitForIndexes": db.limit_for_indexes(),
                "maxIndexLength": db.max_index_length(),
                "maxVarcharLength": db.max_varchar_length(),
                "maxUIDLength": db.max_uid_length(),
                "minDateTime": format(db.min_date_time()),
                "maxDateTime": format(db.max_date_time()),
                "idAttributeType": db.id_attribute_type(),
                "countOfDefaultAttributes": db.count_of_default_attributes(),
                "countOfDefaultIndexes": db.count_of_default_indexes(),
                "documentSizeLimit": db.document_size_limit(),
                "keywords": db.keywords(),
                "internalIndexesKeys": db.internal_indexes_keys(),
            })))
        }
        "adapter.counts" => {
            let c = collection(args)?;
            res(db.try_attribute_width(&c), |width| {
                json!({
                    "countOfAttributes": db.count_of_attributes(&c),
                    "countOfIndexes": db.count_of_indexes(&c),
                    "attributeWidth": width,
                })
            })
        }
        "adapter.capabilities" => {
            let caps: Vec<(&str, Json)> = vec![
                ("schemas", Json::from(db.support_for_schemas())),
                ("attributes", Json::from(db.support_for_attributes())),
                ("setAttributes", Json::from(db.set_support_for_attributes(false))),
                ("schemaAttributes", Json::from(db.support_for_schema_attributes())),
                ("schemaIndexes", Json::from(db.support_for_schema_indexes())),
                ("index", Json::from(db.support_for_index())),
                ("indexArray", Json::from(db.support_for_index_array())),
                ("castIndexArray", Json::from(db.support_for_cast_index_array())),
                ("uniqueIndex", Json::from(db.support_for_unique_index())),
                ("fulltextIndex", Json::from(db.support_for_fulltext_index())),
                ("fulltextWildcardIndex", Json::from(db.support_for_fulltext_wildcard_index())),
                ("casting", Json::from(db.support_for_casting())),
                ("numericCasting", Json::from(db.support_for_numeric_casting())),
                ("queryContains", Json::from(db.support_for_query_contains())),
                ("jsonOverlaps", Json::from(db.support_for_json_overlaps())),
                ("timeouts", Json::from(db.support_for_timeouts())),
                ("relationships", Json::from(db.support_for_relationships())),
                ("updateLock", Json::from(db.support_for_update_lock())),
                ("batchOperations", Json::from(db.support_for_batch_operations())),
                ("attributeResizing", Json::from(db.support_for_attribute_resizing())),
                ("getConnectionId", Json::from(db.support_for_get_connection_id())),
                ("upserts", Json::from(db.support_for_upserts())),
                ("upsertOnUniqueIndex", Json::from(db.support_for_upsert_on_unique_index())),
                ("vectors", Json::from(db.support_for_vectors())),
                ("cacheSkipOnFailure", Json::from(db.support_for_cache_skip_on_failure())),
                ("caching", Json::from(db.support_for_caching())),
                ("reconnection", Json::from(db.support_for_reconnection())),
                ("hostname", Json::from(db.support_for_hostname())),
                ("batchCreateAttributes", Json::from(db.support_for_batch_create_attributes())),
                ("spatialAttributes", Json::from(db.support_for_spatial_attributes())),
                ("object", Json::from(db.support_for_object())),
                ("objectIndexes", Json::from(db.support_for_object_indexes())),
                ("spatialIndexNull", Json::from(db.support_for_spatial_index_null())),
                ("operators", Json::from(db.support_for_operators())),
                (
                    "optionalSpatialAttributeWithExistingRows",
                    Json::from(db.support_for_optional_spatial_attribute_with_existing_rows()),
                ),
                ("spatialIndexOrder", Json::from(db.support_for_spatial_index_order())),
                ("spatialAxisOrder", Json::from(db.support_for_spatial_axis_order())),
                ("boundaryInclusiveContains", Json::from(db.support_for_boundary_inclusive_contains())),
                (
                    "distanceBetweenMultiDimensionGeometryInMeters",
                    Json::from(db.support_for_distance_between_multi_dimension_geometry_in_meters()),
                ),
                ("multipleFulltextIndexes", Json::from(db.support_for_multiple_fulltext_indexes())),
                ("identicalIndexes", Json::from(db.support_for_identical_indexes())),
                ("orderRandom", Json::from(db.support_for_order_random())),
                ("unsignedBigInt", Json::from(db.support_for_unsigned_big_int())),
                ("internalCasting", Json::from(db.support_for_internal_casting())),
                ("utcCasting", Json::from(db.support_for_utc_casting())),
                ("integerBooleans", Json::from(db.support_for_integer_booleans())),
                ("alterLocks", Json::from(db.support_for_alter_locks())),
                ("nonUtfCharacters", Json::from(db.support_non_utf_characters())),
                ("trigramIndex", Json::from(db.support_for_trigram_index())),
                ("pcreRegex", Json::from(db.support_for_pcre_regex())),
                ("posixRegex", Json::from(db.support_for_posix_regex())),
                ("regex", Json::from(db.support_for_regex())),
                ("ttlIndexes", Json::from(db.support_for_ttl_indexes())),
                ("transactionRetries", Json::from(db.support_for_transaction_retries())),
                ("nestedTransactions", Json::from(db.support_for_nested_transactions())),
                ("likeOperator", Json::from(db.like_operator())),
                ("regexOperator", Json::from(db.regex_operator())),
            ];
            Ok(Outcome::Ok(Json::Object(caps.into_iter().map(|(k, v)| (k.to_owned(), v)).collect())))
        }
        _ => Err(Fault::new(format!("database: unknown operation `{op}`"))),
    }
}

/// The fuzz profiles' adapters, one per namespace and driver (PHP keeps
/// them in a static variable of the operation).
type FuzzAdapters = std::collections::HashMap<(String, String), Arc<dyn SqlAdapter>>;
static FUZZ: std::sync::OnceLock<tokio::sync::Mutex<FuzzAdapters>> = std::sync::OnceLock::new();

/// The fuzz profiles' adapter for `driver`: schema `${ns}`, namespace
/// `fz`, collection `fz` seeded with 24 documents and an empty `rt` (as
/// `$fuzzAdapter` in ops/adapter.php). MariaDB and MySQL have no object
/// attributes: their collections have no `meta` (`fz`) nor `o` (`rt`).
async fn fuzz_adapter(session: &Session, driver: &str) -> Result<utopia_database::Result<Arc<dyn SqlAdapter>>, Fault> {
    let mut map = FUZZ.get_or_init(Default::default).lock().await;
    let key = (session.ns.clone(), driver.to_owned());
    if let Some(db) = map.get(&key) {
        return Ok(Ok(db.clone()));
    }
    let db = match connect(driver, session).await? {
        Ok(db) => db,
        Err(e) => return Ok(Err(e)),
    };
    let objects = driver == "postgres";
    let seeded = seed_fuzz(db.as_ref(), &session.ns, objects).await;
    Ok(seeded.map(|()| {
        map.insert(key, db.clone());
        db
    }))
}

/// Creates and seeds the fuzz adapter's database.
async fn seed_fuzz(db: &dyn SqlAdapter, ns: &str, objects: bool) -> utopia_database::Result<()> {
    db.set_authorization(Arc::new(Authorization::new()));
    db.set_database(ns)?;
    db.set_namespace("fz")?;
    // PHP's delete() of a database that does not exist: MariaDB fails, as PHP does then.
    if objects || db.exists(ns, None).await? {
        db.delete(ns).await?;
    }
    db.create(ns).await?;
    let attribute = |id: &str, kind: &str, size: i64, array: bool| {
        Document::from_array_unchecked(
            [
                ("$id", Value::Str(id.into())),
                ("type", Value::Str(kind.into())),
                ("size", Value::Int(size)),
                ("signed", Value::Bool(true)),
                ("array", Value::Bool(array)),
                ("required", Value::Bool(false)),
            ]
            .into_iter()
            .map(|(k, v)| (ArrayKey::from(k), v))
            .collect(),
        )
    };
    let mut fz = vec![
        attribute("title", "string", 64, false),
        attribute("year", "integer", 4, false),
        attribute("rating", "double", 0, false),
        attribute("active", "boolean", 0, false),
        attribute("tags", "string", 32, true),
    ];
    let mut rt = vec![attribute("t", "text", 0, false), attribute("a", "string", 64, true)];
    if objects {
        fz.push(attribute("meta", "object", 0, false));
        rt.push(attribute("o", "object", 0, false));
    }
    fz.push(attribute("released", "datetime", 0, false));
    db.create_collection("fz", &fz, &[]).await?;
    db.create_collection("rt", &rt, &[]).await?;
    let names = ["Alpha", "beta", "Gamma", "delta", "Ünïcödé", "o'brien", "100%", "a_b"];
    let tags: [&[&str]; 4] = [&["x", "y"], &[], &["y"], &["z", "x", "o'b"]];
    let s = |v: &str| Value::Str(v.into());
    let mut documents = Vec::new();
    for i in 0..24i64 {
        let meta: Array =
            [(ArrayKey::from("k"), Value::Int(i % 5)), (ArrayKey::from("s"), s(if i % 2 == 0 { "a" } else { "b" }))]
                .into_iter()
                .collect();
        let mut attrs: Vec<(&str, Value)> = vec![
            ("$id", s(&format!("d{i}"))),
            (
                "$permissions",
                Value::Array([s(if i % 2 == 0 { "read(\"any\")" } else { "read(\"user:1\")" })].into_iter().collect()),
            ),
            ("$createdAt", s(&format!("2001-01-{:02} 00:00:00.000", i + 1))),
            ("$updatedAt", s(&format!("2002-01-{:02} 00:00:00.000", 24 - i))),
            ("title", s(&format!("{} {i}", names[(i % 8) as usize]))),
            ("year", Value::Int(1970 + (i * 7) % 50)),
            ("rating", Value::Float(i as f64 * 0.5 - 3.0)),
            ("active", Value::Bool(i % 3 == 0)),
            ("tags", Value::Array(tags[(i % 4) as usize].iter().map(|t| s(t)).collect())),
        ];
        if objects {
            attrs.push(("meta", Value::Array(meta)));
        }
        attrs.push(("released", s(&format!("2000-01-{:02} 00:00:00.000", i % 28 + 1))));
        let attrs: Array = attrs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect();
        documents.push(Document::from_array_unchecked(attrs));
    }
    db.create_documents(
        &Document::from_array_unchecked([(ArrayKey::from("$id"), s("fz"))].into_iter().collect()),
        documents,
    )
    .await?;
    Ok(())
}

fn collection_of(id: &str) -> Document {
    Document::from_array_unchecked([(ArrayKey::from("$id"), Value::Str(id.into()))].into_iter().collect())
}

async fn fuzz_find(db: &dyn SqlAdapter, args: &Json) -> OpResult {
    let authorization = Authorization::new();
    if args.get("roles").is_some() {
        authorization.clean_roles();
        for role in strings(args, "roles")? {
            authorization.add_role(&role);
        }
    } else {
        authorization.set_status(false);
    }
    db.set_authorization(Arc::new(authorization));
    let queries = match typed_queries(args)? {
        Ok(q) => q,
        Err(e) => return Ok(codec::error(&e)),
    };
    let limit = match args.get("limit") {
        None => Some(25),
        Some(Json::Null) => None,
        Some(_) => Some(int_or(args, "limit", 25)?),
    };
    let offset = match args.get("offset") {
        None | Some(Json::Null) => None,
        Some(_) => Some(int_or(args, "offset", 0)?),
    };
    let order_attributes = strings(args, "order_attributes")?;
    let order_types = strings(args, "order_types")?;
    let cursor = match args.get("cursor") {
        None | Some(Json::Null) => Array::new(),
        Some(j) => codec::decode_array(j)?,
    };
    let direction = text_or(args, "cursor_direction", "after")?;
    let options = FindOptions {
        limit,
        offset,
        order_attributes: &order_attributes,
        order_types: &order_types,
        cursor: &cursor,
        cursor_direction: &direction,
        for_permission: "read",
    };
    res(db.find(&collection_of("fz"), &queries, options).await, |d| codec::encode_documents(&d))
}

/// `objects`: whether the adapter has object attributes (`rt` has `o`).
async fn fuzz_roundtrip(db: &dyn SqlAdapter, args: &Json, objects: bool) -> OpResult {
    let value = value(args, "value")?;
    let authorization = Authorization::new();
    authorization.set_status(false);
    db.set_authorization(Arc::new(authorization));
    let rt = collection_of("rt");
    let mut doc: Vec<(&str, Value)> = vec![
        ("$id", Value::Str("rt".into())),
        ("$permissions", Value::Array(Array::new())),
        ("t", value.clone()),
        ("a", Value::Array([value.clone()].into_iter().collect())),
    ];
    if objects {
        doc.push(("o", Value::Array([(ArrayKey::from("k"), value.clone())].into_iter().collect())));
    }
    let doc: Array = doc.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect();
    let out = |r: utopia_database::Result<Json>| match r {
        Ok(j) => j,
        Err(e) => json!({"error": {"class": e.php_class(), "message": codec::encode(&Value::Str(e.message().into()))}}),
    };
    let created = match Document::new(doc) {
        Ok(d) => db.create_document(&rt, d).await.map(|d| codec::encode_document(&d)),
        Err(e) => Err(e),
    };
    let read = db.get_document(&rt, "rt", &[], false).await.map(|d| codec::encode_document(&d));
    let none = [];
    let found = db
        .find(
            &rt,
            &[Query::new(utopia_database::query::Method::Equal, "t", vec![value])],
            FindOptions {
                limit: Some(25),
                offset: None,
                order_attributes: &none,
                order_types: &none,
                cursor: &Array::new(),
                cursor_direction: "after",
                for_permission: "read",
            },
        )
        .await
        .map(|d| codec::encode_documents(&d));
    let deleted = db.delete_document("rt", "rt").await.map(Json::Bool);
    Ok(Outcome::Ok(Json::Array(vec![out(created), out(read), out(found), out(deleted)])))
}

/// Queries as the Database hands them to the adapter: `attribute_types`
/// (attribute => type) and `arrays` (array attributes) set on every query
/// and nested query of those attributes.
fn typed_queries(args: &Json) -> Result<utopia_database::Result<Vec<Query>>, Fault> {
    let types: Vec<(String, String)> = match value(args, "attribute_types")? {
        Value::Array(a) => a
            .iter()
            .map(|(k, v)| {
                let key = match k {
                    utopia_database::value::KeyRef::Int(i) => i.to_string(),
                    utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
                (key, utopia_database::value::to_text(v))
            })
            .collect(),
        _ => Vec::new(),
    };
    let arrays = strings(args, "arrays")?;
    fn apply(queries: &mut [Query], types: &[(String, String)], arrays: &[String]) {
        for query in queries {
            if let Some((_, t)) = types.iter().rev().find(|(k, _)| k == query.attribute()) {
                query.set_attribute_type(t.clone());
            }
            if arrays.iter().any(|a| a == query.attribute()) {
                query.set_on_array(true);
            }
            if query.is_nested() {
                for v in query.values_mut() {
                    if let Value::Ext(utopia_database::value::Ext::Query(q)) = v {
                        apply(std::slice::from_mut(q.as_mut()), types, arrays);
                    }
                }
            }
        }
    }
    Ok(match codec::queries(args.get("queries").unwrap_or(&Json::Null))? {
        Ok(mut q) => {
            apply(&mut q, &types, &arrays);
            Ok(q)
        }
        Err(e) => Err(e),
    })
}

fn bytes(args: &Json, key: &str) -> Result<Vec<u8>, Fault> {
    match value(args, key)? {
        Value::Str(s) => Ok(s.as_bytes().to_vec()),
        other => Ok(utopia_database::value::to_text(&other).into_bytes()),
    }
}
