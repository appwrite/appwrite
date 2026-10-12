//! Area `schema` of `core`: the session (`db.*`), the authorization
//! (`db.authorization.*`), settings, scopes, filters, the cache, and
//! collections, attributes, indexes, metadata and transactions
//! (`database.*` operations on every other part of `Database`).

use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_database::adapter::Adapter;
use utopia_database::adapter::postgres::Postgres;
use utopia_database::value::{Array, Ext, Value};
use utopia_database::{Authorization, Database};

use super::args::{
    self, Args, Calls, Db, Fail, R, adapter_marker, authorization_marker, cache_marker, database_marker, documents,
    encode, fault, strings,
};
use crate::adapter::{Fault, Session};

/// Operations of this area.
pub const OPS: &[&str] = &[
    "database.add_filter",
    "database.analyze_collection",
    "database.before",
    "database.casting",
    "database.check_attribute",
    "database.clear_timeout",
    "database.create",
    "database.create_attribute",
    "database.create_attributes",
    "database.create_collection",
    "database.create_index",
    "database.decode",
    "database.delete",
    "database.delete_attribute",
    "database.delete_collection",
    "database.delete_index",
    "database.disable_filters",
    "database.disable_validation",
    "database.enable_filters",
    "database.enable_locks",
    "database.enable_validation",
    "database.encode",
    "database.exists",
    "database.get_adapter",
    "database.get_authorization",
    "database.get_cache",
    "database.get_cache_base_keys",
    "database.get_cache_keys",
    "database.get_cache_name",
    "database.get_collection",
    "database.get_connection_id",
    "database.get_database",
    "database.get_drop_unknown_attributes",
    "database.get_global_collections",
    "database.get_id_attribute_type",
    "database.get_instance_filters",
    "database.get_internal_attributes",
    "database.get_keywords",
    "database.get_limit_for_attributes",
    "database.get_limit_for_indexes",
    "database.get_max_query_values",
    "database.get_metadata",
    "database.get_namespace",
    "database.get_preserve_dates",
    "database.get_preserve_sequence",
    "database.get_schema_attributes",
    "database.get_schema_indexes",
    "database.get_shared_tables",
    "database.get_size_of_collection",
    "database.get_size_of_collection_on_disk",
    "database.get_tenant",
    "database.get_tenant_per_document",
    "database.is_migrating",
    "database.list",
    "database.list_collections",
    "database.on",
    "database.ping",
    "database.purge_cached_collection",
    "database.reconnect",
    "database.rename_attribute",
    "database.rename_index",
    "database.reset_global_collections",
    "database.reset_metadata",
    "database.set_authorization",
    "database.set_cache",
    "database.set_cache_name",
    "database.set_database",
    "database.set_drop_unknown_attributes",
    "database.set_global_collections",
    "database.set_max_query_values",
    "database.set_metadata",
    "database.set_migrating",
    "database.set_namespace",
    "database.set_preserve_dates",
    "database.set_preserve_sequence",
    "database.set_shared_tables",
    "database.set_tenant",
    "database.set_tenant_per_document",
    "database.set_timeout",
    "database.silent",
    "database.skip_filters",
    "database.skip_validation",
    "database.update_attribute",
    "database.update_attribute_default",
    "database.update_attribute_filters",
    "database.update_attribute_format",
    "database.update_attribute_format_options",
    "database.update_attribute_required",
    "database.update_collection",
    "database.with_cache",
    "database.with_preserve_dates",
    "database.with_preserve_sequence",
    "database.with_request_timestamp",
    "database.with_tenant",
    "database.with_transaction",
    "db.adapter",
    "db.add_format",
    "db.authorization.add_role",
    "db.authorization.clean_roles",
    "db.authorization.disable",
    "db.authorization.enable",
    "db.authorization.get_description",
    "db.authorization.get_roles",
    "db.authorization.get_status",
    "db.authorization.get_type",
    "db.authorization.has_role",
    "db.authorization.is_array",
    "db.authorization.is_valid",
    "db.authorization.remove_role",
    "db.authorization.reset",
    "db.authorization.set_default_status",
    "db.authorization.set_status",
    "db.authorization.skip",
    "db.log",
    "db.open",
    "db.select",
    "db.sql",
];

/// `true` as JSON.
fn yes() -> R<Json> {
    Ok(Json::Bool(true))
}

/// `null` as JSON (a `void` method).
fn null() -> R<Json> {
    Ok(Json::Null)
}

/// A callback's return value (`None`: it returned nothing).
fn returned(r: Result<Option<Value>, utopia_database::Error>) -> R<Json> {
    Ok(encode(&r?.unwrap_or(Value::Null)))
}

/// The arrays of an `array<array>` argument (`$document` tags are taken as
/// their attributes).
fn arrays(a: &Args<'_>, key: &str) -> R<Vec<Array>> {
    a.array(key)?
        .values()
        .map(|v| match v {
            Value::Array(a) => Ok(a.clone()),
            Value::Object(a) if a.is_empty() => Ok(Array::new()),
            Value::Ext(Ext::Document(d)) => Ok(d.as_array().clone()),
            other => fault(format!("`{key}`: expected arrays, got {}", other.type_name())),
        })
        .collect()
}

/// The values of a list argument.
fn list(a: &Args<'_>, key: &str) -> R<Vec<Value>> {
    Ok(a.array(key)?.values().cloned().collect())
}

/// Runs one operation of this area.
pub async fn call(op: &str, json: &Json, session: &mut Session) -> R<Json> {
    let a = Args::new(json);
    match op {
        "db.open" => return open(&a, session).await,
        "db.select" => {
            let db = args::db_handle(json, session)?;
            args::state(session).current = Some(db);
            return yes();
        }
        "db.log" => return Ok(Json::Array(args::take_log(session))),
        "db.add_format" => {
            args::add_format(&a.string_or("name", "")?)?;
            return yes();
        }
        "database.add_filter" => {
            let name = a.string_or("name", "")?;
            Database::add_filter(&name, args::filter(&name)?);
            return null();
        }
        _ => {}
    }
    let handle = args::db_handle(json, session)?;
    let db = handle.database.clone();
    if let Some(method) = op.strip_prefix("db.authorization.") {
        return authorization(method, &a, &db, session).await;
    }
    match op {
        "db.adapter" => adapter(&a, &db).await,
        "db.sql" => sql(&a, &handle).await,
        "database.on" => {
            let (event, name) = (a.string_or("event", "")?, a.string_or("name", "")?);
            db.on(&event, &name, args::listener(&name, a.raw("callback")));
            Ok(database_marker())
        }
        "database.before" => {
            let (event, name) = (a.string_or("event", "")?, a.string_or("name", "")?);
            db.before(&event, &name, args::transform(&event, &name, a.raw("callback"))?);
            Ok(database_marker())
        }

        // ----- Settings -----
        "database.get_namespace" => Ok(Json::String(db.namespace())),
        "database.set_namespace" => {
            db.set_namespace(&a.string("namespace")?)?;
            Ok(database_marker())
        }
        "database.get_database" => Ok(Json::String(db.database())),
        "database.set_database" => {
            db.set_database(&a.string("name")?)?;
            Ok(database_marker())
        }
        "database.get_id_attribute_type" => Ok(Json::String(db.id_attribute_type().to_owned())),
        "database.get_cache" => Ok(cache_marker()),
        "database.set_cache" => {
            db.set_cache(a.cache("cache")?);
            Ok(database_marker())
        }
        "database.get_cache_name" => Ok(Json::String(db.cache_name())),
        "database.set_cache_name" => {
            db.set_cache_name(&a.string("name")?);
            Ok(database_marker())
        }
        "database.get_metadata" => {
            let metadata = db.metadata();
            if metadata.is_empty() {
                return Ok(Json::Array(Vec::new()));
            }
            Ok(Json::Object(metadata.into_iter().map(|(k, v)| (k, Json::String(v))).collect()))
        }
        "database.set_metadata" => {
            db.set_metadata(&a.string("key")?, &a.string_or("value", "")?);
            Ok(database_marker())
        }
        "database.reset_metadata" => {
            db.reset_metadata();
            null()
        }
        "database.get_authorization" => Ok(authorization_marker()),
        "database.set_authorization" => {
            db.set_authorization(a.authorization("authorization", session)?);
            Ok(database_marker())
        }
        "database.get_adapter" => Ok(adapter_marker()),
        "database.set_timeout" => {
            db.set_timeout(a.int("milliseconds")?, &a.string_or("event", Database::EVENT_ALL)?)?;
            Ok(database_marker())
        }
        "database.clear_timeout" => {
            db.clear_timeout(&a.string_or("event", Database::EVENT_ALL)?);
            null()
        }
        "database.enable_filters" => {
            db.enable_filters();
            Ok(database_marker())
        }
        "database.disable_filters" => {
            db.disable_filters();
            Ok(database_marker())
        }
        "database.get_instance_filters" => {
            let filters = db.instance_filters();
            if filters.is_empty() {
                return Ok(Json::Array(Vec::new()));
            }
            Ok(Json::Object(
                filters
                    .iter()
                    .map(|(name, f)| {
                        let entry = json!({"encode": {"$closure": true}, "decode": {"$closure": true}, "signature": f.signature()});
                        (name.clone(), entry)
                    })
                    .collect(),
            ))
        }
        "database.enable_validation" => {
            db.enable_validation();
            Ok(database_marker())
        }
        "database.disable_validation" => {
            db.disable_validation();
            Ok(database_marker())
        }
        "database.get_shared_tables" => Ok(Json::Bool(db.shared_tables())),
        "database.set_shared_tables" => {
            db.set_shared_tables(a.bool("sharedTables")?);
            Ok(database_marker())
        }
        "database.get_tenant" => Ok(encode(&db.tenant())),
        "database.set_tenant" => {
            db.set_tenant(a.value("tenant")?);
            Ok(database_marker())
        }
        "database.get_tenant_per_document" => Ok(Json::Bool(db.tenant_per_document())),
        "database.set_tenant_per_document" => {
            db.set_tenant_per_document(a.bool("enabled")?);
            Ok(database_marker())
        }
        "database.enable_locks" => {
            db.enable_locks(a.bool("enabled")?);
            Ok(database_marker())
        }
        "database.get_drop_unknown_attributes" => Ok(Json::Bool(db.drop_unknown_attributes())),
        "database.set_drop_unknown_attributes" => {
            db.set_drop_unknown_attributes(a.bool("drop")?);
            Ok(database_marker())
        }
        "database.get_preserve_dates" => Ok(Json::Bool(db.preserve_dates())),
        "database.set_preserve_dates" => {
            db.set_preserve_dates(a.bool("preserve")?);
            Ok(database_marker())
        }
        "database.get_preserve_sequence" => Ok(Json::Bool(db.preserve_sequence())),
        "database.set_preserve_sequence" => {
            db.set_preserve_sequence(a.bool("preserve")?);
            Ok(database_marker())
        }
        "database.is_migrating" => Ok(Json::Bool(db.is_migrating())),
        "database.set_migrating" => {
            db.set_migrating(a.bool("migrating")?);
            Ok(database_marker())
        }
        "database.get_max_query_values" => Ok(Json::from(db.max_query_values())),
        "database.set_max_query_values" => {
            db.set_max_query_values(a.int("max")?);
            Ok(database_marker())
        }
        "database.get_global_collections" => Ok(strings(&db.global_collections())),
        "database.set_global_collections" => {
            db.set_global_collections(&a.strings("collections")?);
            Ok(database_marker())
        }
        "database.reset_global_collections" => {
            db.reset_global_collections();
            null()
        }
        "database.get_keywords" => Ok(strings(db.keywords())),
        "database.get_limit_for_attributes" => Ok(Json::from(db.limit_for_attributes())),
        "database.get_limit_for_indexes" => Ok(Json::from(db.limit_for_indexes())),
        "database.get_internal_attributes" => Ok(Json::Array(
            db.internal_attributes().iter().map(|a| encode(&Value::Array(a.as_array().clone()))).collect(),
        )),
        "database.get_connection_id" => Ok(Json::String(db.connection_id().await?)),
        "database.ping" => Ok(Json::Bool(db.ping().await?)),
        "database.reconnect" => {
            db.reconnect().await?;
            null()
        }

        // ----- Scopes -----
        "database.silent" => {
            let listeners = a.opt_strings("listeners")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.silent(|| cb.invoke(Vec::new()), listeners.as_deref()).await);
            calls.finish(r)
        }
        "database.skip_filters" => {
            let filters = a.opt_strings("filters")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.skip_filters(|| cb.invoke(Vec::new()), filters.as_deref()).await);
            calls.finish(r)
        }
        "database.skip_validation" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.skip_validation(|| cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_tenant" => {
            let tenant = a.value("tenant")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.with_tenant(tenant, || cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_preserve_dates" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.with_preserve_dates(|| cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_preserve_sequence" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.with_preserve_sequence(|| cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_request_timestamp" => {
            let timestamp = a.datetime("requestTimestamp")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.with_request_timestamp(timestamp, || cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_transaction" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(db.with_transaction(|| cb.invoke(Vec::new())).await);
            calls.finish(r)
        }
        "database.with_cache" => {
            let key = a.string("key")?;
            // `?string $hash = ''`: absent is '', null bypasses the cache.
            let hash = if a.has("hash") { a.opt_string("hash")? } else { Some(String::new()) };
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = db
                .with_cache(
                    &key,
                    || async { cb.invoke(Vec::new()).await.map(|v| v.unwrap_or(Value::Null)) },
                    hash.as_deref(),
                )
                .await;
            calls.finish(r.map(|v| encode(&v)).map_err(Fail::from))
        }

        // ----- Databases -----
        "database.create" => Ok(Json::Bool(db.create(a.opt_string("database")?.as_deref()).await?)),
        "database.exists" => Ok(Json::Bool(
            db.exists(a.opt_string("database")?.as_deref(), a.opt_string("collection")?.as_deref()).await?,
        )),
        "database.list" => Ok(documents(&db.list().await?)),
        "database.delete" => Ok(Json::Bool(db.delete(a.opt_string("database")?.as_deref()).await?)),

        // ----- Cache -----
        "database.get_cache_base_keys" => {
            let (collection, document) =
                db.cache_base_keys(&a.string("collectionId")?, a.opt_string("documentId")?.as_deref());
            Ok(json!([collection, document]))
        }
        "database.get_cache_keys" => {
            let (collection, document, hash) = db.cache_keys(
                &a.string("collectionId")?,
                a.opt_string("documentId")?.as_deref(),
                &a.strings("selects")?,
            );
            Ok(json!([collection, document, hash]))
        }
        "database.purge_cached_collection" => {
            Ok(Json::Bool(db.purge_cached_collection(&a.string("collectionId")?).await?))
        }

        // ----- Encoding -----
        "database.encode" => {
            let collection = a.document("collection")?;
            Ok(args::document(
                &db.encode(&collection, a.document("document")?, a.bool_or("applyDefaults", true)?).await?,
            ))
        }
        "database.decode" => {
            let collection = a.document("collection")?;
            Ok(args::document(&db.decode(&collection, a.document("document")?, &a.strings("selections")?).await?))
        }
        "database.casting" => {
            let collection = a.document("collection")?;
            Ok(args::document(&db.casting(&collection, a.document("document")?)?))
        }

        // ----- Collections -----
        "database.create_collection" => Ok(args::document(
            &db.create_collection(
                &a.string("id")?,
                a.documents("attributes")?,
                a.documents("indexes")?,
                a.opt_strings("permissions")?,
                a.bool_or("documentSecurity", true)?,
            )
            .await?,
        )),
        "database.update_collection" => Ok(args::document(
            &db.update_collection(&a.string("id")?, a.strings("permissions")?, a.bool("documentSecurity")?).await?,
        )),
        "database.get_collection" => Ok(args::document(&db.get_collection(&a.string("id")?).await?)),
        "database.list_collections" => {
            Ok(documents(&db.list_collections(a.int_or("limit", 25)?, a.int_or("offset", 0)?).await?))
        }
        "database.get_size_of_collection" => Ok(Json::from(db.size_of_collection(&a.string("collection")?).await?)),
        "database.get_size_of_collection_on_disk" => {
            Ok(Json::from(db.size_of_collection_on_disk(&a.string("collection")?).await?))
        }
        "database.analyze_collection" => Ok(Json::Bool(db.analyze_collection(&a.string("collection")?).await?)),
        "database.delete_collection" => Ok(Json::Bool(db.delete_collection(&a.string("id")?).await?)),
        "database.get_schema_attributes" => Ok(documents(&db.schema_attributes(&a.string("collection")?).await?)),
        "database.get_schema_indexes" => Ok(documents(&db.schema_indexes(&a.string("collection")?).await?)),

        // ----- Attributes -----
        "database.create_attribute" => Ok(Json::Bool(
            db.create_attribute(
                &a.string("collection")?,
                &a.string("id")?,
                &a.string("type")?,
                a.int("size")?,
                a.bool("required")?,
                a.value("default")?,
                a.bool_or("signed", true)?,
                a.bool_or("array", false)?,
                a.opt_string("format")?.as_deref(),
                a.array("formatOptions")?,
                a.strings("filters")?,
            )
            .await?,
        )),
        "database.create_attributes" => {
            Ok(Json::Bool(db.create_attributes(&a.string("collection")?, arrays(&a, "attributes")?).await?))
        }
        "database.update_attribute_required" => Ok(args::document(
            &db.update_attribute_required(&a.string("collection")?, &a.string("id")?, a.bool("required")?).await?,
        )),
        "database.update_attribute_format" => Ok(args::document(
            &db.update_attribute_format(&a.string("collection")?, &a.string("id")?, &a.string("format")?).await?,
        )),
        "database.update_attribute_format_options" => Ok(args::document(
            &db.update_attribute_format_options(&a.string("collection")?, &a.string("id")?, a.array("formatOptions")?)
                .await?,
        )),
        "database.update_attribute_filters" => Ok(args::document(
            &db.update_attribute_filters(&a.string("collection")?, &a.string("id")?, a.strings("filters")?).await?,
        )),
        "database.update_attribute_default" => Ok(args::document(
            &db.update_attribute_default(&a.string("collection")?, &a.string("id")?, a.value("default")?).await?,
        )),
        "database.update_attribute" => {
            let format_options = if a.given("formatOptions") { Some(a.array("formatOptions")?) } else { None };
            Ok(args::document(
                &db.update_attribute(
                    &a.string("collection")?,
                    &a.string("id")?,
                    a.opt_string("type")?.as_deref(),
                    a.opt_int("size")?,
                    a.opt_bool("required")?,
                    a.value("default")?,
                    a.opt_bool("signed")?,
                    a.opt_bool("array")?,
                    a.opt_string("format")?.as_deref(),
                    format_options,
                    a.opt_strings("filters")?,
                    a.opt_string("newKey")?.as_deref(),
                )
                .await?,
            ))
        }
        "database.check_attribute" => {
            Ok(Json::Bool(db.check_attribute(&a.document("collection")?, &a.document("attribute")?)?))
        }
        "database.delete_attribute" => {
            Ok(Json::Bool(db.delete_attribute(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.rename_attribute" => {
            Ok(Json::Bool(db.rename_attribute(&a.string("collection")?, &a.string("old")?, &a.string("new")?).await?))
        }

        // ----- Indexes -----
        "database.create_index" => Ok(Json::Bool(
            db.create_index(
                &a.string("collection")?,
                &a.string("id")?,
                &a.string("type")?,
                a.strings("attributes")?,
                list(&a, "lengths")?,
                list(&a, "orders")?,
                a.int_or("ttl", 1)?,
            )
            .await?,
        )),
        "database.rename_index" => {
            Ok(Json::Bool(db.rename_index(&a.string("collection")?, &a.string("old")?, &a.string("new")?).await?))
        }
        "database.delete_index" => Ok(Json::Bool(db.delete_index(&a.string("collection")?, &a.string("id")?).await?)),

        _ => fault(format!("database: unknown operation `{op}`")),
    }
}

/// `db.open`: a database on PostgreSQL service `database-postgres` (or on
/// the adapter of the database handle `adapter`), made current unless it
/// is such a peer, and created unless `create` is false (`Core::open`).
async fn open(a: &Args<'_>, session: &mut Session) -> R<Json> {
    const KNOWN: &[&str] = &[
        "namespace",
        "database",
        "shared_tables",
        "tenant",
        "tenant_per_document",
        "cache",
        "create",
        "roles",
        "adapter",
    ];
    if let Some(o) = a.raw_object() {
        for key in o.keys() {
            if !KNOWN.contains(&key.as_str()) {
                return fault(format!("db.open: unknown argument `{key}`"));
            }
        }
    }
    let peer = a.given("adapter");
    let (adapter, postgres): (Arc<dyn Adapter>, Option<Arc<Postgres>>) = if peer {
        let db = session
            .get::<Db>(a.raw("adapter").unwrap_or(&Json::Null))
            .map_err(|_| Fault::new("db.open: `adapter` is not a database handle"))?;
        (db.database.adapter().clone(), db.postgres.clone())
    } else {
        let url = session.service("database-postgres")?.to_owned();
        let postgres = Arc::new(Postgres::connect(&url).await?);
        (postgres.clone(), Some(postgres))
    };
    let cache = args::cache(&a.string_or("cache", "memory")?)?;
    let db = Database::new(adapter, cache, Vec::new());
    let authorization = Authorization::new();
    let roles = match a.opt_strings("roles")? {
        Some(roles) => roles,
        None => vec!["any".to_owned()],
    };
    for role in roles {
        authorization.add_role(&role);
    }
    db.set_authorization(Arc::new(authorization));
    if !peer {
        let ns = session.ns.clone();
        db.set_database(&a.string_or("database", &ns)?)?;
    }
    if a.has("shared_tables") {
        db.set_shared_tables(a.bool_or("shared_tables", false)?);
    }
    if a.has("tenant") {
        db.set_tenant(a.value("tenant")?);
    }
    if a.has("tenant_per_document") {
        db.set_tenant_per_document(a.bool_or("tenant_per_document", false)?);
    }
    if !peer || a.has("namespace") {
        db.set_namespace(&a.string_or("namespace", "compat")?)?;
    }
    let handle = Db { database: db.clone(), postgres };
    if !peer {
        args::state(session).current = Some(handle.clone());
        if a.bool_or("create", true)? {
            db.create(None).await?;
        }
    }
    Ok(session.handle(handle))
}

/// `db.authorization.<method>`: the database's `Authorization`.
async fn authorization(method: &str, a: &Args<'_>, db: &Database, session: &mut Session) -> R<Json> {
    let auth = db.authorization();
    match method {
        "add_role" => auth.add_role(&a.string("role")?),
        "remove_role" => auth.remove_role(&a.string("role")?),
        "clean_roles" => auth.clean_roles(),
        "has_role" => return Ok(Json::Bool(auth.has_role(&a.string("role")?))),
        "get_roles" => return Ok(Json::Array(auth.roles().iter().map(encode).collect())),
        "get_status" => return Ok(Json::Bool(auth.status())),
        "set_status" => auth.set_status(a.bool("status")?),
        "set_default_status" => auth.set_default_status(a.bool("status")?),
        "enable" => auth.enable(),
        "disable" => auth.disable(),
        "reset" => auth.reset(),
        "get_description" => return Ok(Json::String(auth.description())),
        "is_array" => return Ok(Json::Bool(false)),
        "get_type" => return Ok(Json::String("array".to_owned())),
        // Only an `Authorization\Input` object is valid input, and none
        // crosses the wire.
        "is_valid" => return Ok(Json::Bool(false)),
        "skip" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = returned(auth.skip(|| cb.invoke(Vec::new())).await);
            return calls.finish(r);
        }
        _ => return fault(format!("database: unknown operation `db.authorization.{method}`")),
    }
    null()
}

/// `db.adapter`: `$db->getAdapter()->$method(...$args)`.
async fn adapter(a: &Args<'_>, db: &Database) -> R<Json> {
    let method = a.string_or("method", "")?;
    let empty = Json::Object(Map::new());
    let args = Args::new(a.raw("args").filter(|j| !j.is_null()).unwrap_or(&empty));
    let adapter = db.adapter();
    macro_rules! flags {
        ($($php:literal => $rust:ident),* $(,)?) => {
            match method.as_str() {
                $($php => return Ok(Json::from(adapter.$rust())),)*
                _ => {}
            }
        };
    }
    flags! {
        "getSupportForSchemas" => support_for_schemas,
        "getSupportForAttributes" => support_for_attributes,
        "getSupportForSchemaAttributes" => support_for_schema_attributes,
        "getSupportForSchemaIndexes" => support_for_schema_indexes,
        "getSupportForIndex" => support_for_index,
        "getSupportForIndexArray" => support_for_index_array,
        "getSupportForCastIndexArray" => support_for_cast_index_array,
        "getSupportForUniqueIndex" => support_for_unique_index,
        "getSupportForFulltextIndex" => support_for_fulltext_index,
        "getSupportForFulltextWildcardIndex" => support_for_fulltext_wildcard_index,
        "getSupportForCasting" => support_for_casting,
        "getSupportForQueryContains" => support_for_query_contains,
        "getSupportForTimeouts" => support_for_timeouts,
        "getSupportForRelationships" => support_for_relationships,
        "getSupportForUpdateLock" => support_for_update_lock,
        "getSupportForBatchOperations" => support_for_batch_operations,
        "getSupportForAttributeResizing" => support_for_attribute_resizing,
        "getSupportForGetConnectionId" => support_for_get_connection_id,
        "getSupportForUpserts" => support_for_upserts,
        "getSupportForUpsertOnUniqueIndex" => support_for_upsert_on_unique_index,
        "getSupportForVectors" => support_for_vectors,
        "getSupportForCacheSkipOnFailure" => support_for_cache_skip_on_failure,
        "getSupportForCaching" => support_for_caching,
        "getSupportForReconnection" => support_for_reconnection,
        "getSupportForHostname" => support_for_hostname,
        "getSupportForBatchCreateAttributes" => support_for_batch_create_attributes,
        "getSupportForSpatialAttributes" => support_for_spatial_attributes,
        "getSupportForObject" => support_for_object,
        "getSupportForObjectIndexes" => support_for_object_indexes,
        "getSupportForSpatialIndexNull" => support_for_spatial_index_null,
        "getSupportForOperators" => support_for_operators,
        "getSupportForOptionalSpatialAttributeWithExistingRows" => support_for_optional_spatial_attribute_with_existing_rows,
        "getSupportForSpatialIndexOrder" => support_for_spatial_index_order,
        "getSupportForSpatialAxisOrder" => support_for_spatial_axis_order,
        "getSupportForBoundaryInclusiveContains" => support_for_boundary_inclusive_contains,
        "getSupportForDistanceBetweenMultiDimensionGeometryInMeters" => support_for_distance_between_multi_dimension_geometry_in_meters,
        "getSupportForMultipleFulltextIndexes" => support_for_multiple_fulltext_indexes,
        "getSupportForIdenticalIndexes" => support_for_identical_indexes,
        "getSupportForOrderRandom" => support_for_order_random,
        "getSupportForUnsignedBigInt" => support_for_unsigned_big_int,
        "getSupportForInternalCasting" => support_for_internal_casting,
        "getSupportForUTCCasting" => support_for_utc_casting,
        "getSupportForIntegerBooleans" => support_for_integer_booleans,
        "getSupportForAlterLocks" => support_for_alter_locks,
        "getSupportNonUtfCharacters" => support_non_utf_characters,
        "getSupportForTrigramIndex" => support_for_trigram_index,
        "getSupportForPCRERegex" => support_for_pcre_regex,
        "getSupportForPOSIXRegex" => support_for_posix_regex,
        "getSupportForRegex" => support_for_regex,
        "getSupportForTTLIndexes" => support_for_ttl_indexes,
        "getSupportForTransactionRetries" => support_for_transaction_retries,
        "getSupportForNestedTransactions" => support_for_nested_transactions,
        "getLimitForString" => limit_for_string,
        "getLimitForInt" => limit_for_int,
        "getLimitForBigInt" => limit_for_big_int,
        "getLimitForAttributes" => limit_for_attributes,
        "getLimitForIndexes" => limit_for_indexes,
        "getMaxIndexLength" => max_index_length,
        "getMaxVarcharLength" => max_varchar_length,
        "getMaxUIDLength" => max_uid_length,
        "getCountOfDefaultAttributes" => count_of_default_attributes,
        "getCountOfDefaultIndexes" => count_of_default_indexes,
        "getDocumentSizeLimit" => document_size_limit,
        "getIdAttributeType" => id_attribute_type,
        "getSharedTables" => shared_tables,
        "getTenantPerDocument" => tenant_per_document,
        "inTransaction" => in_transaction,
        "getHostname" => hostname,
        "getNamespace" => namespace,
        "getDatabase" => database,
    }
    match method.as_str() {
        "getTenant" => Ok(encode(&adapter.tenant())),
        "getMinDateTime" => Ok(args::datetime(&adapter.min_date_time())),
        "getMaxDateTime" => Ok(args::datetime(&adapter.max_date_time())),
        "getInternalIndexesKeys" => Ok(strings(adapter.internal_indexes_keys())),
        "getKeywords" => Ok(strings(adapter.keywords())),
        "getAttributeWidth" => Ok(Json::from(adapter.attribute_width(&args.document("collection")?))),
        "getCountOfAttributes" => Ok(Json::from(adapter.count_of_attributes(&args.document("collection")?))),
        "getCountOfIndexes" => Ok(Json::from(adapter.count_of_indexes(&args.document("collection")?))),
        "filter" => Ok(Json::String(adapter.filter(&args.string("value")?)?)),
        "createCollection" => Ok(Json::Bool(
            adapter
                .create_collection(&args.string("name")?, &args.documents("attributes")?, &args.documents("indexes")?)
                .await?,
        )),
        "deleteCollection" => Ok(Json::Bool(adapter.delete_collection(&args.string("id")?).await?)),
        "createDocument" => Ok(args::document(
            &adapter.create_document(&args.document("collection")?, args.document("document")?).await?,
        )),
        "getDocument" => Ok(args::document(
            &adapter
                .get_document(
                    &args.document("collection")?,
                    &args.string("id")?,
                    &args.queries("queries")?,
                    args.bool_or("forUpdate", false)?,
                )
                .await?,
        )),
        "ping" => Ok(Json::Bool(adapter.ping().await?)),
        _ => fault(format!("db.adapter: no method `{method}` in the compat glue")),
    }
}

/// `db.sql`: a statement on the database's own connection, naming no
/// schema but the database's (`Core::sql`).
async fn sql(a: &Args<'_>, handle: &Db) -> R<Json> {
    let sql = a.string_or("sql", "")?;
    let schema = handle.database.database();
    let bytes = sql.as_bytes();
    let mut at = 0;
    while let Some(found) = sql[at..].find("\".") {
        let quote = at + found;
        let start = bytes[..quote].iter().rposition(|&b| b == b'"');
        let name = match start {
            Some(s) => &sql[s + 1..quote],
            None => "",
        };
        if name != schema {
            return fault(format!("db.sql: only schema `{schema}` may be named, not `{name}`"));
        }
        at = quote + 2;
    }
    let Some(postgres) = &handle.postgres else {
        return fault("db.sql: this database has no connection of its own");
    };
    let rows = postgres.driver_query(&sql).await?;
    Ok(Json::Array(rows.into_iter().map(|r| encode(&Value::Array(r))).collect()))
}
