//! Area `adapter`: the adapters without a `Database` (`adapter.open`,
//! `adapter.call`, `db.adapter`, `object.call` on an adapter), and the fuzz
//! programs (`adapter.fuzz_*`, `mongo.fuzz_*`). The twin of
//! `tests/compat/database/ops/adapter.php`.
//!
//! [`invoke`] is an adapter's public methods by their PHP names.

use std::sync::Arc;

use futures_util::future::BoxFuture;
use serde_json::{Value as Json, json};
use utopia_database::adapter::memory::Memory as MemoryAdapter;
use utopia_database::adapter::pool::Pool as PoolAdapter;
use utopia_database::adapter::sql::pdo::{FetchMode, Options as PdoOptions, Pdo};
use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::adapter::{Adapter, Feature, Find};
use utopia_database::capability::Capability;
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::database::Hook;
use utopia_database::document::Document;
use utopia_database::hook::{Tenancy, Write};
use utopia_database::permission::PermissionType;
use utopia_database::query::Query;
use utopia_database::relationship::{self, RelationshipSide};
use utopia_database::validator::authorization::Authorization;
use utopia_database::value::{self, Array, ArrayKey, Str, Value};
use utopia_database::{Attribute, Index};
use utopia_database::{Error, Kind, Relationship};
use utopia_query::schema::ColumnType;
use utopia_query::{CursorDirection, OrderDirection};

use super::args::{self, Args};
use super::calls::Calls;
use super::codec;
use super::convert::{IntoObj, object, objects, ok, strings};
use super::ctx::{Fail, R, fault};
use super::database::{AuthorizationObj, BuilderObj, ProfilerObj, SchemaObj};
use super::enums;
use super::fixtures;
use super::fixtures::HookObj;
use super::obj::Obj;
use super::open::{self, AdapterObj};
use super::values::LimitsObj;

/// Runs one `adapter` operation.
pub async fn call(op: &str, a: &Args) -> R<Obj> {
    match op {
        "adapter.open" => adapter_open(a).await,
        "adapter.call" => {
            let adapter = adapter_param(a, "adapter")?;
            let inner = Args::new(&a.cx, a.raw("args").unwrap_or(&Json::Null))?;
            invoke(&adapter, &a.string_or("method", "")?, &inner).await
        }
        "adapter.fuzz_find" | "adapter.fuzz_roundtrip" => {
            let driver = if a.string_or("engine", "postgres")? == "sqlite" {
                "sqlite".to_owned()
            } else {
                a.string_or("driver", "postgres")?
            };
            let adapter = match fuzz_adapter(a, &driver).await? {
                Ok(adapter) => adapter,
                Err(e) => return Err(Fail::Error(e)),
            };
            if op == "adapter.fuzz_find" {
                fuzz_find(&*adapter, a).await
            } else {
                fuzz_roundtrip(&*adapter, a, matches!(driver.as_str(), "postgres")).await
            }
        }
        "mongo.fuzz_find" | "mongo.fuzz_roundtrip" => {
            let adapter = match fuzz_adapter(a, "mongo").await? {
                Ok(adapter) => adapter,
                Err(e) => return Err(Fail::Error(e)),
            };
            if op == "mongo.fuzz_find" {
                fuzz_find(&*adapter, a).await
            } else {
                fuzz_roundtrip(&*adapter, a, true).await
            }
        }
        _ => fault(format!("database: unknown operation `{op}`")),
    }
}

/// The adapter a parameter names: an adapter handle, a database handle
/// (its adapter), or a stored adapter or database.
pub fn adapter_param(a: &Args, key: &str) -> R<Arc<dyn Adapter>> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    let obj = if j.get("$handle").is_some() { a.cx.handled(j)? } else { args::decode_obj(&a.cx, j)? };
    if let Some(adapter) = obj.downcast_ref::<AdapterObj>() {
        return Ok(adapter.0.clone());
    }
    if let Some(db) = obj.downcast_ref::<super::database::DatabaseObj>() {
        return Ok(db.0.adapter().clone());
    }
    fault(format!("`{key}` is not an adapter"))
}

/// An authorization of `roles` (cleaned first) and `status`
/// (`$authorization` of ops/adapter.php).
fn authorization(a: &Args) -> R<Arc<Authorization>> {
    let authorization = Authorization::new(true);
    if a.has("roles") {
        authorization.clean_roles();
        for role in a.strings("roles")? {
            authorization.add_role(&role);
        }
    }
    if a.has("status") {
        authorization.set_status(a.bool_or("status", true)?);
    }
    Ok(Arc::new(authorization))
}

/// A bare adapter of `driver` or `engine`, one connection of its own.
async fn connect(a: &Args) -> R<Arc<dyn Adapter>> {
    match a.string_or("engine", "postgres")?.as_str() {
        "sqlite" => Ok(open::sqlite(&a.cx, &a.string_or("file", "adapter.sqlite")?).await?),
        "memory" => Ok(Arc::new(MemoryAdapter::new())),
        _ => Ok(open::connect(&a.cx, &a.string_or("driver", "postgres")?).await?),
    }
}

/// `adapter.open`.
async fn adapter_open(a: &Args) -> R<Obj> {
    let adapter = connect(a).await?;
    adapter.set_authorization(authorization(a)?);
    let hooks = match a.raw("hooks") {
        None | Some(Json::Null) => vec![Json::from("Permissions")],
        Some(list) => codec::list(list)?.into_iter().cloned().collect(),
    };
    for hook in hooks {
        match fixtures::make_hook(&hook)?.hook {
            utopia_database::database::Hook::Write(hook, _) => adapter.add_write_hook(hook),
            _ => return fault("adapter.open: `hooks` are write hooks"),
        }
    }
    adapter.set_database(&a.string_or("database", &a.cx.ns)?);
    adapter.set_namespace(&a.string_or("namespace", "ns")?);
    if a.has("shared_tables") {
        adapter.set_shared_tables(a.bool_or("shared_tables", false)?);
    }
    if a.has("tenant") {
        adapter.set_tenant(a.value("tenant")?);
    }
    if a.has("tenant_per_document") {
        adapter.set_tenant_per_document(a.bool_or("tenant_per_document", false)?);
    }
    Ok(Obj::Value(codec::decode(&a.cx.handle(object(AdapterObj(adapter))))?))
}

/// `new Adapter\<class>(...)`.
pub async fn construct(class: &str, a: &Args) -> R<Option<Obj>> {
    let engine = match class {
        "Adapter\\Memory" => return Ok(Some(object(AdapterObj(Arc::new(MemoryAdapter::new()))))),
        "Adapter\\SQLite" => Engine::Sqlite,
        "Adapter\\MariaDB" => Engine::MariaDb,
        "Adapter\\MySQL" => Engine::MySql,
        "Adapter\\Postgres" => Engine::Postgres,
        _ => return Ok(None),
    };
    let pdo = match pdo_of(a, "pdo").await? {
        Ok(pdo) => pdo,
        Err(e) => return Err(Fail::Error(e)),
    };
    let sql = Arc::new(Sql::new(engine, pdo));
    if matches!(a.raw("pdo"), Some(Json::Object(o)) if o.is_empty()) {
        let mut stubs = super::ctx::lock(&STUBS);
        stubs.retain(|w| w.strong_count() > 0);
        stubs.push(Arc::downgrade(&sql));
    }
    Ok(Some(object(AdapterObj(sql))))
}

/// The SQL adapters a test built over a stub object instead of a PDO
/// (`new MariaDB($stub)`).
static STUBS: std::sync::LazyLock<std::sync::Mutex<Vec<std::sync::Weak<Sql>>>> =
    std::sync::LazyLock::new(|| std::sync::Mutex::new(Vec::new()));

/// The methods of an adapter over a stub object that never reach its
/// connection; any other one asks `getDriver()` for the PDO, which refuses
/// a stub (`SQL::getDriver()`).
const CONNECTION_FREE: &[&str] = &[
    "supports",
    "capabilities",
    "limits",
    "hasFeature",
    "setAuthorization",
    "getAuthorization",
    "setProfiler",
    "getProfiler",
    "setDatabase",
    "getDatabase",
    "setNamespace",
    "getNamespace",
    "setHostname",
    "setSharedTables",
    "hasSharedTables",
    "setTenant",
    "getTenant",
    "withTenant",
    "setTenantPerDocument",
    "isTenantPerDocument",
    "setMetadata",
    "getMetadata",
    "resetMetadata",
    "setLocks",
    "addWriteHook",
    "removeWriteHook",
    "getWriteHooks",
    "getTenantHook",
    "addTransform",
    "removeTransform",
    "resetTransforms",
    "inTransaction",
    "ignoreDuplicates",
    "setTimeout",
    "clearTimeout",
    "getTimeout",
    "encode",
    "decode",
    "getColumnType",
    "getSchemaIndexType",
    "getAttributeWidth",
    "getCountOfAttributes",
    "getCountOfIndexes",
    "filter",
    "builder",
    "schema",
    "update",
];

/// Whether `adapter` is a SQL adapter over a stub object.
fn is_stub(adapter: &Arc<dyn Adapter>) -> bool {
    let Some(sql) = adapter.as_any().downcast_ref::<Sql>() else {
        return false;
    };
    super::ctx::lock(&STUBS).iter().any(|w| w.upgrade().is_some_and(|s| std::ptr::eq(&*s, sql)))
}

/// The PDO a unit test built (`Core::pdoOf`): `{"$pdo": {"dsn", "wrapper"?,
/// "username"?, "password"?, "options"?}}`. Both `\PDO` and
/// `Utopia\Database\PDO` are a [`Pdo`] here. A stub object (`{}`, a PHPUnit
/// stub PHP never runs a statement on) is an in-memory SQLite connection,
/// as the tests that need a connection-free adapter build one.
async fn pdo_of(a: &Args, key: &str) -> R<utopia_database::Result<Pdo>> {
    let spec = match a.raw(key) {
        Some(Json::Object(o)) => match o.get("$pdo") {
            Some(Json::Object(spec)) => spec.clone(),
            Some(_) => return fault("`$pdo` is {\"dsn\", ...}"),
            None if o.is_empty() => serde_json::Map::new(),
            None => return fault(format!("`{key}` is not a PDO")),
        },
        _ => return fault(format!("`{key}` is not a PDO")),
    };
    let mut dsn = spec.get("dsn").and_then(Json::as_str).unwrap_or("sqlite::memory:").to_owned();
    // A SQLite file under /tmp lives in this run's directory: PHP's /tmp is
    // its container's, fresh each run, and a file a previous run left here
    // would hold its collections.
    if let Some(rest) = dsn.strip_prefix("sqlite:/tmp/") {
        let dir = format!("/tmp/compat-fs/{}", a.cx.ns);
        std::fs::create_dir_all(&dir).map_err(|e| Fail::Fault(crate::adapter::Fault::new(format!("sqlite: {e}"))))?;
        dsn = format!("sqlite:{dir}/{rest}");
    }
    let dsn = dsn.as_str();
    let text = |k: &str| spec.get(k).and_then(Json::as_str).map(str::to_owned);
    // A PDO without attributes fetches native numbers (PDO's default
    // ATTR_STRINGIFY_FETCHES is off).
    let mut options = PdoOptions { stringify_fetches: false, ..PdoOptions::default() };
    if let Some(Json::Object(attributes)) = spec.get("options") {
        // PDO::ATTR_STRINGIFY_FETCHES (17).
        if let Some(stringify) = attributes.get("17") {
            options.stringify_fetches =
                stringify.as_bool().unwrap_or(false) || stringify.as_i64().is_some_and(|v| v != 0);
        }
        // PDO::ATTR_TIMEOUT (2) and PDO::ATTR_DEFAULT_FETCH_MODE (19); the
        // others (persistence, error mode, emulation) are how the Rust
        // handle always behaves.
        if let Some(seconds) = attributes.get("2").and_then(Json::as_u64) {
            options.timeout = std::time::Duration::from_secs(seconds);
        }
        match attributes.get("19").and_then(Json::as_i64) {
            Some(2) => options.fetch_mode = FetchMode::Assoc,
            Some(3) => options.fetch_mode = FetchMode::Num,
            Some(4) => options.fetch_mode = FetchMode::Both,
            _ => {}
        }
    }
    Ok(Pdo::connect_with(dsn, text("username").as_deref(), text("password").as_deref(), options).await)
}

/// `Adapter\<class>::<method>(...)`, and `Connection::hasError()` (its
/// `{"$throwable": ...}` argument).
pub async fn call_static(class: &str, method: &str, a: &Args) -> R<Option<Obj>> {
    Ok(match (class, method) {
        ("Connection", "hasError") => Some(has_error(a.raw("e").unwrap_or(&Json::Null))?.into_obj()),
        (_, "filter") if class.starts_with("Adapter\\") => {
            Some(utopia_database::adapter::filter(&a.string("value")?).into_obj())
        }
        _ => None,
    })
}

/// `Connection::hasError($e)` of a `{"$throwable": {"class", "message",
/// "code"?, "errorInfo"?, "previous"?}}` (`Core::throwable`): a
/// `PDOException` whose `errorInfo` names a lost connection, or a message
/// that does. The previous exceptions are not consulted, as in PHP.
fn has_error(e: &Json) -> R<bool> {
    let Some(spec) = e.get("$throwable").and_then(Json::as_object) else {
        return fault("`e` is {\"$throwable\": {...}}");
    };
    let class = spec.get("class").and_then(Json::as_str).unwrap_or("Exception").trim_start_matches('\\');
    if class == "PDOException" || class.ends_with("\\PDOException") {
        let info = spec.get("errorInfo").and_then(Json::as_array);
        let state = info.and_then(|i| i.first()).and_then(Json::as_str);
        let code = info.and_then(|i| i.get(1)).and_then(Json::as_i64);
        if utopia_database::connection::has_lost_connection_code(state, code) {
            return Ok(true);
        }
    }
    let message = spec.get("message").and_then(Json::as_str).unwrap_or_default();
    Ok(utopia_database::connection::has_error(message))
}

/// A `Document $collection` parameter of an adapter method. PHP hands the
/// adapter the plain document (`Core::argument`), where an absent
/// `documentSecurity` reads as `false`; a Rust `Collection` defaults it to
/// `true`, so a plain document without it gets `false` here. A
/// `Collection` object keeps its own.
fn collection(a: &Args, key: &str) -> R<Collection> {
    let document = a.document(key)?;
    as_collection(document)
}

fn as_collection(mut document: Document) -> R<Collection> {
    if document.class() != utopia_database::collection::CLASS
        && !document.has(utopia_database::collection::DOCUMENT_SECURITY)
    {
        document.set(utopia_database::collection::DOCUMENT_SECURITY, Value::Bool(false))?;
    }
    Ok(Collection::from_document(&document)?)
}

/// Calls `method` (PHP's name) of `adapter`.
pub async fn invoke(adapter: &Arc<dyn Adapter>, method: &str, a: &Args) -> R<Obj> {
    if !CONNECTION_FREE.contains(&method) && is_stub(adapter) {
        return Err(Fail::Error(Error::new(
            Kind::Database,
            "SQL adapter requires Utopia\\Database\\PDO, Swoole\\Database\\PDOProxy, or PDO",
        )));
    }
    let calls = Calls::new(&a.cx);
    let result = call_method(adapter, method, a, &calls).await;
    calls.finish(result)
}

async fn call_method(adapter: &Arc<dyn Adapter>, method: &str, a: &Args, calls: &Calls) -> R<Obj> {
    let s = |k: &str| a.string(k);
    let this = || object(AdapterObj(adapter.clone()));
    Ok(match method {
        // ----- Profile -----
        "supports" => adapter.supports(capability(a, "capability")?).into_obj(),
        "capabilities" => Obj::List(adapter.capabilities().iter().map(enums::obj).collect()),
        "limits" => object(LimitsObj(adapter.limits())),
        "hasFeature" => adapter.has_feature(feature(&s("feature")?)?).into_obj(),
        // ----- Settings -----
        "setAuthorization" => {
            adapter.set_authorization(super::database::authorization(a, "authorization")?);
            this()
        }
        "getAuthorization" => object(AuthorizationObj(adapter.authorization())),
        "getProfiler" => adapter.profiler().map(|p| object(ProfilerObj(p))).into_obj(),
        "setDatabase" => {
            adapter.set_database(&s("name")?);
            this()
        }
        "getDatabase" => adapter.database().into_obj(),
        "setNamespace" => {
            adapter.set_namespace(&s("namespace")?);
            this()
        }
        "getNamespace" => adapter.namespace().into_obj(),
        "setHostname" => {
            adapter.set_hostname(&s("hostname")?);
            this()
        }
        "setSharedTables" => {
            adapter.set_shared_tables(a.bool("sharedTables")?);
            this()
        }
        "hasSharedTables" => adapter.shared_tables().into_obj(),
        "setTenant" => {
            adapter.set_tenant(a.value("tenant")?);
            this()
        }
        "getTenant" => adapter.tenant().into_obj(),
        "setTenantPerDocument" => {
            adapter.set_tenant_per_document(a.bool("tenantPerDocument")?);
            this()
        }
        "isTenantPerDocument" => adapter.tenant_per_document().into_obj(),
        "setMetadata" => {
            adapter.set_metadata(&s("key")?, a.value("value")?);
            this()
        }
        "getMetadata" => {
            let mut out = Array::new();
            for (k, v) in adapter.metadata() {
                out.set(ArrayKey::from(k.as_str()), v);
            }
            out.into_obj()
        }
        "resetMetadata" => {
            adapter.reset_metadata();
            Obj::null()
        }
        "setLocks" => {
            adapter.set_locks(a.bool("locks")?);
            this()
        }
        "addWriteHook" => {
            match super::database::hook(a, "hook")?.hook {
                utopia_database::database::Hook::Write(hook, _) => adapter.add_write_hook(hook),
                _ => return fault("addWriteHook takes a write hook"),
            }
            this()
        }
        "removeWriteHook" => {
            match a.raw("hook") {
                Some(Json::String(name)) if fixtures::hook_class(name).ends_with("Tenancy") => {
                    adapter.remove_tenancy_hooks()
                }
                _ => match super::database::hook(a, "hook")?.hook {
                    utopia_database::database::Hook::Write(hook, _) => adapter.remove_write_hook(&hook),
                    _ => return fault("removeWriteHook takes a write hook"),
                },
            }
            this()
        }
        "addTransform" => {
            match super::database::hook(a, "transform")?.hook {
                utopia_database::database::Hook::Transform(hook, _) => adapter.add_transform(&s("name")?, hook),
                _ => return fault("addTransform takes a transform"),
            }
            this()
        }
        "removeTransform" => {
            adapter.remove_transform(&fixtures::hook_class(&s("name")?));
            this()
        }
        "resetTransforms" => {
            adapter.reset_transforms();
            Obj::null()
        }
        // ----- Transactions -----
        "startTransaction" => ok(adapter.start_transaction().await)?,
        "commitTransaction" => ok(adapter.commit_transaction().await)?,
        "rollbackTransaction" => ok(adapter.rollback_transaction().await)?,
        "inTransaction" => adapter.in_transaction().into_obj(),
        "withTransaction" => {
            let cb = calls.required("callback", a.raw("callback"))?;
            let result = utopia_database::adapter::with_transaction(&**adapter, || cb.invoke(Vec::new())).await;
            match result {
                Ok(v) => v.unwrap_or_else(Obj::null),
                Err(e) => return Err(Fail::Error(e)),
            }
        }
        // ----- Databases -----
        "create" => ok(adapter.create(&s("name")?).await)?,
        "update" => ok(adapter.update(&s("name")?, &s("new")?).await)?,
        "exists" => match a.opt_string("collection")? {
            Some(collection) => ok(adapter.collection_exists(&s("database")?, &collection).await)?,
            None => ok(adapter.exists(&s("database")?).await)?,
        },
        "collectionExists" => ok(adapter.collection_exists(&s("database")?, &s("collection")?).await)?,
        "list" => ok(adapter.list().await)?,
        "delete" => ok(adapter.delete(&s("name")?).await)?,
        // ----- Collections -----
        "createCollection" => ok(adapter
            .create_collection(&s("collection")?, &a.list::<Attribute>("attributes")?, &a.list::<Index>("indexes")?)
            .await)?,
        "deleteCollection" => ok(adapter.delete_collection(&s("collection")?).await)?,
        "analyzeCollection" => ok(adapter.analyze_collection(&s("collection")?).await)?,
        "getSizeOfCollection" => ok(adapter.size_of_collection(&s("collection")?).await)?,
        "getSizeOfCollectionOnDisk" => ok(adapter.size_of_collection_on_disk(&s("collection")?).await)?,
        "getAttributeWidth" => ok(adapter.attribute_width(&collection(a, "collection")?))?,
        "getCountOfAttributes" => ok(adapter.count_of_attributes(&collection(a, "collection")?))?,
        "getCountOfIndexes" => ok(adapter.count_of_indexes(&collection(a, "collection")?))?,
        // ----- Attributes -----
        "createAttribute" => ok(adapter.create_attribute(&s("collection")?, &a.req::<Attribute>("attribute")?).await)?,
        "createAttributes" => {
            ok(adapter.create_attributes(&s("collection")?, &a.list::<Attribute>("attributes")?).await)?
        }
        "updateAttribute" => {
            ok(adapter.update_attribute(&s("collection")?, &s("key")?, &a.req::<Attribute>("attribute")?).await)?
        }
        "deleteAttribute" => ok(adapter.delete_attribute(&s("collection")?, &s("key")?).await)?,
        "renameAttribute" => ok(adapter.rename_attribute(&s("collection")?, &s("old")?, &s("new")?).await)?,
        "getSchemaAttributes" => objects(adapter.schema_attributes(&s("collection")?).await?),
        "getSchemaIndexes" => objects(adapter.schema_indexes(&s("collection")?).await?),
        "getColumnType" => ok(adapter.column_type(&a.req::<Attribute>("attribute")?))?,
        // ----- Indexes -----
        "createIndex" => {
            let types = match a.raw("indexAttributeTypes").or(a.raw("attributeTypes")) {
                Some(Json::Object(map)) => map
                    .iter()
                    .map(|(k, v)| Ok((k.clone(), super::values::enum_of::<ColumnType>(v)?)))
                    .collect::<R<Vec<_>>>()?,
                _ => Vec::new(),
            };
            let collation = match a.raw("collation") {
                Some(Json::Object(map)) => {
                    map.iter().map(|(k, v)| (k.clone(), v.as_str().unwrap_or_default().to_owned())).collect()
                }
                _ => Vec::new(),
            };
            ok(adapter.create_index(&s("collection")?, &a.req::<Index>("index")?, &types, &collation).await)?
        }
        "deleteIndex" => ok(adapter.delete_index(&s("collection")?, &s("key")?).await)?,
        "renameIndex" => ok(adapter.rename_index(&s("collection")?, &s("old")?, &s("new")?).await)?,
        // ----- Documents -----
        "createDocument" => ok(adapter.create_document(&collection(a, "collection")?, a.document("document")?).await)?,
        "createDocuments" => {
            ok(adapter.create_documents(&collection(a, "collection")?, a.documents("documents")?).await)?
        }
        "getDocument" => ok(adapter
            .get_document(
                &collection(a, "collection")?,
                &s("id")?,
                a.queries("queries")?,
                a.bool_or("forUpdate", false)?,
            )
            .await)?,
        "updateDocument" => ok(adapter
            .update_document(
                &collection(a, "collection")?,
                &s("id")?,
                a.document("document")?,
                a.bool_or("skipPermissions", false)?,
            )
            .await)?,
        "updateDocuments" => ok(adapter
            .update_documents(
                &collection(a, "collection")?,
                &a.document("updates")?,
                a.documents("documents")?,
                &a.strings("skipPermissions")?,
            )
            .await)?,
        "increaseDocumentAttribute" => ok(adapter
            .increase_document_attribute(
                &collection(a, "collection")?,
                &s("id")?,
                &s("attribute")?,
                a.value("value")?,
                &a.string_or("updatedAt", "")?,
                a.opt_value("min")?.filter(|v| !matches!(v, Value::Null)),
                a.opt_value("max")?.filter(|v| !matches!(v, Value::Null)),
            )
            .await)?,
        "deleteDocument" => ok(adapter.delete_document(&collection(a, "collection")?, &s("id")?).await)?,
        "deleteDocuments" => {
            let sequences = match a.value("sequences")? {
                Value::Array(arr) => arr.into_iter().map(|(_, v)| v).collect(),
                _ => Vec::new(),
            };
            ok(adapter.delete_documents(&collection(a, "collection")?, sequences, a.strings("permissionIds")?).await)?
        }
        "find" => ok(adapter.find(&collection(a, "collection")?, a.queries("queries")?, find_options(a)?).await)?,
        "count" => ok(adapter.count(&collection(a, "collection")?, a.queries("queries")?, a.opt_int("max")?).await)?,
        "sum" => ok(adapter
            .sum(&collection(a, "collection")?, &s("attribute")?, a.queries("queries")?, a.opt_int("max")?)
            .await)?,
        "getSequences" => ok(adapter.get_sequences(&collection(a, "collection")?, a.documents("documents")?).await)?,
        // ----- Features -----
        "ping" => match adapter.connection() {
            Some(c) => ok(c.ping().await)?,
            None => return fault("the adapter has no connection"),
        },
        "reconnect" => match adapter.connection() {
            Some(c) => ok(c.reconnect().await)?,
            None => return fault("the adapter has no connection"),
        },
        "id" | "getConnectionId" => match adapter.connection() {
            Some(c) => ok(c.id().await)?,
            None => return fault("the adapter has no connection"),
        },
        "hostname" | "getHostname" => match adapter.connection() {
            Some(c) => ok(c.hostname().await)?,
            None => return fault("the adapter has no connection"),
        },
        "rawQuery" => match adapter.raw_query() {
            Some(r) => ok(r.raw_query(&s("query")?, value::to_query_array(&a.array("bindings")?)).await)?,
            None => return fault("the adapter has no raw queries"),
        },
        "rawMutation" => match adapter.raw_query() {
            Some(r) => ok(r.raw_mutation(&s("query")?, value::to_query_array(&a.array("bindings")?)).await)?,
            None => return fault("the adapter has no raw queries"),
        },
        "setTimeout" => match adapter.timeouts() {
            Some(t) => {
                t.set_timeout(
                    a.int("milliseconds")?,
                    a.enum_::<utopia_database::event::Event>("event")?.unwrap_or(utopia_database::event::Event::All),
                )?;
                Obj::null()
            }
            None => return fault("the adapter has no timeouts"),
        },
        "clearTimeout" => match adapter.timeouts() {
            Some(t) => {
                t.clear_timeout(
                    a.enum_::<utopia_database::event::Event>("event")?.unwrap_or(utopia_database::event::Event::All),
                );
                Obj::null()
            }
            None => return fault("the adapter has no timeouts"),
        },
        "getTimeout" => match adapter.timeouts() {
            Some(t) => t
                .timeout(
                    a.enum_::<utopia_database::event::Event>("event")?.unwrap_or(utopia_database::event::Event::All),
                )
                .into_obj(),
            None => return fault("the adapter has no timeouts"),
        },
        "filter" => utopia_database::adapter::filter(&s("value")?).into_obj(),
        // ----- Scopes and hooks -----
        "withTenant" => {
            let cb = calls.required("callback", a.raw("callback"))?;
            returned(adapter.state().tenant().with(a.value("tenant")?, cb.invoke(Vec::new())).await)?
        }
        "ignoreDuplicates" => {
            let cb = calls.required("callback", a.raw("callback"))?;
            returned(adapter.state().ignoring_duplicates().with(true, cb.invoke(Vec::new())).await)?
        }
        "getTenantHook" => match adapter.tenant_hook() {
            Some(tenancy) => object(HookObj { hook: Hook::write(Arc::new(tenancy)), class: "Tenancy" }),
            None => Obj::null(),
        },
        "getWriteHooks" => Obj::List(adapter.write_hooks().into_iter().map(write_hook).collect()),
        "setProfiler" => {
            let profiler = match a.obj("profiler")? {
                Some(obj) => Some(args::downcast::<ProfilerObj>(&obj)?.0),
                None => None,
            };
            adapter.set_profiler(profiler);
            this()
        }
        "isRetryable" => adapter.is_retryable(&throwable(a.raw("failure").unwrap_or(&Json::Null))?).into_obj(),
        "getDriver" => driver(adapter)?,
        "getReadConcurrency" => (adapter.read_concurrency() as i64).into_obj(),
        "relaxAttributeRequired" => ok(adapter.relax_attribute_required(&s("collection")?, &s("id")?).await)?,
        "getSchemaIndexType" => {
            enums::obj(adapter.schema_index_type(a.req_enum::<utopia_query::schema::IndexType>("type")?))
        }
        // ----- Relationships -----
        "createRelationship" => ok(relationships(adapter)?
            .create_relationship(&s("collection")?, &a.req::<Relationship>("relationship")?)
            .await)?,
        "updateRelationship" => ok(relationships(adapter)?
            .update_relationship(
                &s("collection")?,
                &a.req::<Relationship>("relationship")?,
                a.req_enum::<RelationshipSide>("side")?,
                &a.req::<relationship::Update>("update")?,
            )
            .await)?,
        "deleteRelationship" => ok(relationships(adapter)?
            .delete_relationship(
                &s("collection")?,
                &a.req::<Relationship>("relationship")?,
                a.req_enum::<RelationshipSide>("side")?,
            )
            .await)?,
        // ----- Upserts -----
        "upsertDocument" => {
            ok(upserts(adapter)?.upsert_document(&collection(a, "collection")?, a.req::<Change>("change")?).await)?
        }
        "upsertDocuments" => ok(upserts(adapter)?
            .upsert_documents(
                &collection(a, "collection")?,
                a.list::<Change>("changes")?,
                a.opt_string("increase")?.as_deref(),
            )
            .await)?,
        // ----- Spatial -----
        "encode" => ok(spatial(adapter)?.encode(&a.value("value")?, a.req_enum::<ColumnType>("type")?))?,
        "decode" => {
            let bytes = match a.value("value")? {
                Value::Str(s) => s.as_bytes().to_vec(),
                other => value::to_text(&other).into_bytes(),
            };
            ok(spatial(adapter)?.decode(&bytes, a.req_enum::<ColumnType>("type")?))?
        }
        // ----- Casting -----
        "castBefore" => ok(casting(adapter)?.cast_before(&collection(a, "collection")?, a.document("document")?))?,
        "castAfter" => ok(casting(adapter)?.cast_after(&collection(a, "collection")?, a.documents("documents")?))?,
        "castDatetime" => ok(casting(adapter)?.cast_datetime(&s("value")?))?,
        // ----- Schemaless -----
        "setSchemaless" => match adapter.schemaless() {
            Some(sl) => {
                sl.set_schemaless(a.bool("schemaless")?);
                this()
            }
            None => return fault(format!("{} has no method `setSchemaless`", open::adapter_class(&**adapter))),
        },
        "isSchemaless" => match adapter.schemaless() {
            Some(sl) => sl.is_schemaless().into_obj(),
            None => return fault(format!("{} has no method `isSchemaless`", open::adapter_class(&**adapter))),
        },
        // ----- Builders -----
        "builder" => match adapter.query_builder() {
            Some(q) => object(BuilderObj(Arc::new(std::sync::Mutex::new(q.builder()?)), None)),
            None => return fault(format!("{} has no method `builder`", open::adapter_class(&**adapter))),
        },
        "schema" => match adapter.query_builder() {
            Some(q) => object(SchemaObj(Arc::new(std::sync::Mutex::new(q.schema()?)))),
            None => return fault(format!("{} has no method `schema`", open::adapter_class(&**adapter))),
        },
        // ----- Pool -----
        "delegate" => return Box::pin(delegate(adapter, &s("method")?, a)).await,
        "getPool" => return fault("Pool::getPool(): the pool is not an object of the harness"),
        _ => return fault(format!("{} has no method `{method}`", open::adapter_class(&**adapter))),
    })
}

/// What a scope's callback returned, as the scope method returns it.
fn returned(r: utopia_database::Result<Option<Obj>>) -> R<Obj> {
    match r {
        Ok(v) => Ok(v.unwrap_or_else(Obj::null)),
        Err(e) => Err(Fail::Error(e)),
    }
}

/// A registered write hook as an object: `Tenancy` or, for any other,
/// `Permissions` (the only other write hook the cases register; the trait
/// object does not say its class).
fn write_hook(hook: Arc<dyn Write>) -> Obj {
    if hook.tenancy().is_some() {
        object(HookObj { hook: Hook::Write(hook, std::any::type_name::<Tenancy>()), class: "Tenancy" })
    } else {
        object(HookObj {
            hook: Hook::Write(hook, std::any::type_name::<utopia_database::hook::Permissions>()),
            class: "Permissions",
        })
    }
}

/// A `{"$throwable": {"class", "message", "errorInfo"?, "previous"?}}` as
/// the library's error (`Core::throwable`).
fn throwable(json: &Json) -> R<Error> {
    let Some(spec) = json.get("$throwable").and_then(Json::as_object) else {
        return fault("a throwable is {\"$throwable\": {...}}");
    };
    let class = spec.get("class").and_then(Json::as_str).unwrap_or("Exception").trim_start_matches('\\');
    let kind = Kind::from_php_class(class).unwrap_or(Kind::Exception);
    let mut error = Error::new(kind, spec.get("message").and_then(Json::as_str).unwrap_or_default());
    if let Some(info) = spec.get("errorInfo").and_then(Json::as_array) {
        if let Some(state) = info.first().and_then(Json::as_str) {
            error = error.with_string_code(state.as_bytes());
        }
        if let Some(code) = info.get(1).and_then(Json::as_i64) {
            error = error.with_driver_code(code);
        }
    }
    if let Some(previous) = spec.get("previous").filter(|p| p.is_object()) {
        error = error.with_previous(throwable(&json!({"$throwable": previous}))?);
    }
    Ok(error)
}

/// A PDO handle as PHP reports it (`{"$value": "PDO", "fields": {}}`).
#[derive(Clone)]
struct PdoObj;
crate::php_object!(PdoObj, "PDO", |_p| json!({"$value": "PDO", "fields": {}}));

/// `getDriver()`: a SQL adapter's PDO, the Memory adapter itself, a Pool's
/// connection's driver.
fn driver(adapter: &Arc<dyn Adapter>) -> R<Obj> {
    let any = adapter.as_any();
    if any.is::<Sql>() {
        return Ok(object(PdoObj));
    }
    if any.is::<MemoryAdapter>() {
        return Ok(object(AdapterObj(adapter.clone())));
    }
    if let Some(pool) = any.downcast_ref::<PoolAdapter>()
        && let Some(pinned) = pool.pinned_adapter()
    {
        return driver(&pinned);
    }
    if any.is::<PoolAdapter>() {
        return Ok(object(PdoObj));
    }
    fault(format!("{}::getDriver(): the client is not an object of the harness", open::adapter_class(&**adapter)))
}

fn relationships(adapter: &Arc<dyn Adapter>) -> R<&dyn utopia_database::adapter::Relationships> {
    match adapter.relationships() {
        Some(r) => Ok(r),
        None => fault(format!("{} has no relationships", open::adapter_class(&**adapter))),
    }
}

fn upserts(adapter: &Arc<dyn Adapter>) -> R<&dyn utopia_database::adapter::Upserts> {
    match adapter.upserts() {
        Some(u) => Ok(u),
        None => fault(format!("{} has no upserts", open::adapter_class(&**adapter))),
    }
}

fn spatial(adapter: &Arc<dyn Adapter>) -> R<&dyn utopia_database::adapter::Spatial> {
    match adapter.spatial() {
        Some(s) => Ok(s),
        None => fault(format!("{} has no spatial codec", open::adapter_class(&**adapter))),
    }
}

fn casting(adapter: &Arc<dyn Adapter>) -> R<&dyn utopia_database::adapter::Casting> {
    match adapter.casting() {
        Some(c) => Ok(c),
        None => fault(format!("{} has no casting", open::adapter_class(&**adapter))),
    }
}

/// The parameters of the methods `Pool::delegate()` is called with, in
/// order (its `$arguments` are positional).
const DELEGATED: &[(&str, &[&str])] = &[
    ("setNamespace", &["namespace"]),
    ("setDatabase", &["name"]),
    ("setTenant", &["tenant"]),
    ("setSharedTables", &["sharedTables"]),
    ("getNamespace", &[]),
    ("getDatabase", &[]),
    ("getTenant", &[]),
    ("hasSharedTables", &[]),
    ("getDriver", &[]),
    ("ping", &[]),
    ("id", &[]),
    ("hostname", &[]),
    ("exists", &["database", "collection"]),
    ("collectionExists", &["database", "collection"]),
];

/// `Pool::delegate($method, $arguments)`: the method run on the connection
/// the pool lends (the pinned one in a transaction), its result as is.
fn delegate<'a>(adapter: &'a Arc<dyn Adapter>, method: &'a str, a: &'a Args) -> BoxFuture<'a, R<Obj>> {
    Box::pin(async move {
        let Some(pool) = adapter.as_any().downcast_ref::<PoolAdapter>() else {
            return fault(format!("{} has no method `delegate`", open::adapter_class(&**adapter)));
        };
        let Some((_, names)) = DELEGATED.iter().find(|(m, _)| *m == method) else {
            return fault(format!("delegate: no parameter names for `{method}`"));
        };
        let positional = match a.raw("arguments") {
            Some(list) => codec::list(list)?.into_iter().cloned().collect::<Vec<_>>(),
            None => Vec::new(),
        };
        let named: serde_json::Map<String, Json> =
            names.iter().zip(positional).map(|(name, v)| ((*name).to_owned(), v)).collect();
        let inner = Args::new(&a.cx, &Json::Object(named))?;
        if let Some(pinned) = pool.pinned_adapter() {
            return invoke(&pinned, method, &inner).await;
        }
        let connection = match pool.pool().pop().await {
            Ok(connection) => connection,
            Err(e) => return Err(Fail::Error(Error::new(Kind::Exception, e.to_string()))),
        };
        let borrowed = connection.resource().0.clone();
        let result = invoke(&borrowed, method, &inner).await;
        pool.pool().reclaim(&connection);
        result
    })
}

/// The `find()` options from PHP's parameters (`limit`, `offset`,
/// `orderAttributes`, `orderTypes`, `cursor`, `cursorDirection`,
/// `forPermission`).
fn find_options(a: &Args) -> R<Find> {
    let order_types = match a.raw("orderTypes") {
        Some(list) => {
            codec::list(list)?.into_iter().map(super::values::enum_of::<OrderDirection>).collect::<R<Vec<_>>>()?
        }
        None => Vec::new(),
    };
    Ok(Find {
        limit: if a.has("limit") { a.opt_int("limit")? } else { Some(25) },
        offset: a.opt_int("offset")?,
        order_attributes: a.strings("orderAttributes")?,
        order_types,
        cursor: a.array("cursor")?,
        cursor_direction: a.enum_::<CursorDirection>("cursorDirection")?.unwrap_or(CursorDirection::After),
        for_permission: a.enum_::<PermissionType>("forPermission")?.unwrap_or(PermissionType::Read),
    })
}

/// A `Capability` parameter.
fn capability(a: &Args, key: &str) -> R<Capability> {
    a.req_enum::<Capability>(key)
}

/// A feature by its PHP interface (`Feature\Upserts::class`).
fn feature(name: &str) -> R<Feature> {
    match Feature::ALL.into_iter().find(|f| f.php_class() == name || f.php_class().ends_with(&format!("\\{name}"))) {
        Some(f) => Ok(f),
        None => fault(format!("no adapter feature `{name}`")),
    }
}

// ----- Fuzz programs -----

/// The fuzz profiles' adapter for `driver`, one per namespace: schema
/// `${ns}`, namespace `fz`, collection `fz` seeded with 24 documents and an
/// empty `rt` (`$fuzzAdapter`).
async fn fuzz_adapter(a: &Args, driver: &str) -> R<utopia_database::Result<Arc<dyn Adapter>>> {
    let mut map = a.cx.fuzz.lock().await;
    if let Some(adapter) = map.get(driver) {
        return Ok(Ok(adapter.clone()));
    }
    let adapter: Arc<dyn Adapter> = match driver {
        "sqlite" => open::sqlite(&a.cx, "fuzz.sqlite").await?,
        "mongo" => open::mongo(&a.cx, &a.cx.ns).await?,
        other => open::connect(&a.cx, other).await?,
    };
    let objects = matches!(driver, "postgres" | "mongo");
    if let Err(e) = seed(&*adapter, &a.cx.ns, objects).await {
        return Ok(Err(e));
    }
    map.insert(driver.to_owned(), adapter.clone());
    Ok(Ok(adapter))
}

fn doc_of(pairs: Vec<(&str, Value)>) -> Document {
    Document::from_array_unchecked(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect())
}

/// `new Document(['$id' => $id])` as the adapter's collection: no
/// `documentSecurity` (see [`collection`]).
fn collection_named(id: &str) -> utopia_database::Result<Collection> {
    Collection::from_document(&doc_of(vec![
        ("$id", Value::Str(Str::from(id))),
        (utopia_database::collection::DOCUMENT_SECURITY, Value::Bool(false)),
    ]))
}

/// Seeds the fuzz database (`$fuzzSeed`).
async fn seed(adapter: &dyn Adapter, ns: &str, objects: bool) -> utopia_database::Result<()> {
    adapter.set_authorization(Arc::new(Authorization::new(true)));
    adapter.add_write_hook(Arc::new(utopia_database::hook::Permissions::new()));
    adapter.set_database(ns);
    adapter.set_namespace("fz");
    if adapter.exists(ns).await? {
        adapter.delete(ns).await?;
    }
    adapter.create(ns).await?;
    let mut fz = vec![
        Attribute::string("title", 64),
        Attribute::integer("year"),
        Attribute::double("rating"),
        Attribute::boolean("active"),
        Attribute::string("tags", 32).array(true),
    ];
    if objects {
        fz.push(Attribute::object("meta"));
    }
    fz.push(Attribute::datetime("released"));
    adapter.create_collection("fz", &fz, &[]).await?;
    let mut rt = vec![Attribute::text("t"), Attribute::string("a", 64).array(true)];
    if objects {
        rt.push(Attribute::object("o"));
    }
    adapter.create_collection("rt", &rt, &[]).await?;
    let names = ["Alpha", "beta", "Gamma", "delta", "Ünïcödé", "o'brien", "100%", "a_b"];
    let tags: [&[&str]; 4] = [&["x", "y"], &[], &["y"], &["z", "x", "o'b"]];
    let s = |v: &str| Value::Str(Str::from(v));
    let mut documents = Vec::new();
    for i in 0..24i64 {
        let mut pairs = vec![
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
            let meta: Array = [
                (ArrayKey::from("k"), Value::Int(i % 5)),
                (ArrayKey::from("s"), s(if i % 2 == 0 { "a" } else { "b" })),
            ]
            .into_iter()
            .collect();
            pairs.push(("meta", Value::Array(meta)));
        }
        pairs.push(("released", s(&format!("2000-01-{:02} 00:00:00.000", i % 28 + 1))));
        documents.push(Document::new(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect())?);
    }
    adapter.create_documents(&collection_named("fz")?, documents).await?;
    Ok(())
}

/// Queries as the Database hands them to the adapter: `attribute_types`
/// and `arrays` set on every query and nested query of those attributes.
fn typed_queries(a: &Args) -> R<Vec<Query>> {
    let types: Vec<(String, String)> = match a.value("attribute_types")? {
        Value::Array(arr) => arr
            .iter()
            .map(|(k, v)| {
                let key = match k {
                    value::KeyRef::Int(i) => i.to_string(),
                    value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
                };
                (key, value::to_text(v))
            })
            .collect(),
        _ => Vec::new(),
    };
    let arrays = a.strings("arrays")?;
    fn apply(query: &mut utopia_query::Query, types: &[(String, String)], arrays: &[String]) {
        if let Some((_, t)) = types.iter().rev().find(|(k, _)| k == query.attribute()) {
            query.set_attribute_type(t.clone());
        }
        if arrays.iter().any(|a| a == query.attribute()) {
            query.set_on_array(true);
        }
        if query.is_nested() {
            let values: utopia_query::Array = query
                .values()
                .clone()
                .into_iter()
                .map(|(k, mut v)| {
                    if let utopia_query::Value::Ext(utopia_query::Object::Query(q)) = &mut v {
                        apply(q, types, arrays);
                    }
                    (k, v)
                })
                .collect();
            query.set_values(values);
        }
    }
    let mut queries = a.queries("queries")?;
    for q in &mut queries {
        apply(q, &types, &arrays);
    }
    Ok(queries)
}

/// `adapter.fuzz_find`, `mongo.fuzz_find` (`$fuzzFind`).
async fn fuzz_find(adapter: &dyn Adapter, a: &Args) -> R<Obj> {
    let authorization = if a.has("roles") {
        authorization(a)?
    } else {
        let auth = Authorization::new(true);
        auth.set_status(false);
        Arc::new(auth)
    };
    adapter.set_authorization(authorization);
    let mut order_attributes = a.strings("order_attributes")?;
    let mut order_types: Vec<OrderDirection> = a
        .strings("order_types")?
        .iter()
        .map(|t| OrderDirection::try_from_value(&t.to_uppercase()).unwrap_or(OrderDirection::Asc))
        .collect();
    if !order_attributes.iter().any(|a| a == "$sequence") {
        order_types.truncate(order_attributes.len());
        order_types.resize(order_attributes.len(), OrderDirection::Asc);
        order_attributes.push("$sequence".to_owned());
        order_types.push(OrderDirection::Asc);
    }
    let queries = typed_queries(a)?;
    let options = Find {
        limit: if a.has("limit") { a.opt_int("limit")? } else { Some(25) },
        offset: a.opt_int("offset")?,
        order_attributes,
        order_types,
        cursor: a.array("cursor")?,
        cursor_direction: CursorDirection::try_from_value(&a.string_or("cursor_direction", "after")?)
            .unwrap_or(CursorDirection::After),
        for_permission: PermissionType::Read,
    };
    ok(adapter.find(&collection_named("fz")?, queries, options).await)
}

/// `adapter.fuzz_roundtrip`, `mongo.fuzz_roundtrip` (`$fuzzRoundtrip`):
/// `objects` when `rt` has the object attribute `o`.
async fn fuzz_roundtrip(adapter: &dyn Adapter, a: &Args, objects: bool) -> R<Obj> {
    let value = a.value("value")?;
    let auth = Authorization::new(true);
    auth.set_status(false);
    adapter.set_authorization(Arc::new(auth));
    let rt = collection_named("rt")?;
    let out = |r: utopia_database::Result<Json>| match r {
        Ok(j) => j,
        Err(e) => json!({"error": {"class": e.php_class(), "message": crate::adapter::bytes_value(e.message_bytes())}}),
    };
    let mut pairs = vec![
        ("$id", Value::Str(Str::from("rt"))),
        ("$permissions", Value::Array(Array::new())),
        ("t", value.clone()),
        ("a", Value::Array([value.clone()].into_iter().collect())),
    ];
    if objects {
        pairs.push(("o", Value::Array([(ArrayKey::from("k"), value.clone())].into_iter().collect())));
    }
    let created = match Document::new(pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect()) {
        Ok(d) => adapter.create_document(&rt, d).await.map(|d| codec::encode_document(&d)),
        Err(e) => Err(e),
    };
    let read = adapter.get_document(&rt, "rt", Vec::new(), false).await.map(|d| codec::encode_document(&d));
    let query = Query::new(utopia_query::Method::Equal, "t", [value::to_query(&value)].into_iter().collect(), "");
    let found = adapter.find(&rt, vec![query], Find::default()).await.map(|d| codec::encode_documents(&d));
    let deleted = adapter.delete_document(&rt, "rt").await.map(Json::Bool);
    Ok(Obj::Value(codec::decode(&Json::Array(vec![out(created), out(read), out(found), out(deleted)]))?))
}

#[allow(dead_code)]
fn unused() -> Obj {
    strings::<&str>(&[])
}
