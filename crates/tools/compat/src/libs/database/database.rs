//! `Utopia\Database\Database`'s public methods by name (`database.<method>`
//! in snake case, `object.call` on a database in camel case): each decodes
//! the method's parameters by their PHP names and calls the Rust method.

use std::sync::Arc;

use futures_util::future::BoxFuture;
use futures_util::{FutureExt as _, StreamExt as _};
use serde_json::{Value as Json, json};
use utopia_database::database::{self as dbmod, Callback};
use utopia_database::document::Document;
use utopia_database::event::Event;
use utopia_database::permission::PermissionType;
use utopia_database::query::Query;
use utopia_database::value::{Str, Value};
use utopia_database::{Attribute, Database, Error, Index, Relationship, attribute, collection, relationship};
use utopia_query::CursorDirection;

use super::args::{self, Args};
use super::calls::{Calls, Cb};
use super::codec;
use super::convert::{IntoObj, object, objects, ok, strings};
use super::ctx::{Fail, R, fault};
use super::fixtures::{self, CodecObj, HookObj};
use super::obj::Obj;
use super::open::{AdapterObj, CacheObj};
use super::values::LimitsObj;
use crate::php_object;

/// A database held as an object (`{"$database": true}`): the same database
/// for every copy.
#[derive(Clone)]
pub struct DatabaseObj(pub Database);
php_object!(DatabaseObj, "Database", |_d| json!({"$database": true}));

/// An authorization held as an object.
#[derive(Clone)]
pub struct AuthorizationObj(pub Arc<utopia_database::Authorization>);
php_object!(AuthorizationObj, "Validator\\Authorization", |_a| json!({"$authorization": true}));

/// A profiler held as an object.
#[derive(Clone)]
pub struct ProfilerObj(pub Arc<utopia_database::Profiler>);
php_object!(ProfilerObj, "Profiler", |_p| json!({"$profiler": true}));

/// A query cache held as an object.
#[derive(Clone)]
pub struct QueryCacheObj(pub Arc<utopia_database::cache::Query>);
php_object!(QueryCacheObj, "Cache\\Query", |_q| json!({"$queryCache": true}));

/// A filter registry held as an object (`{"$registry": [<names>]}`).
#[derive(Clone)]
pub struct RegistryObj(pub Arc<std::sync::RwLock<utopia_database::filter::Registry>>);
php_object!(RegistryObj, "Filter\\Registry", |r| {
    let registry = r.0.read().unwrap_or_else(|e| e.into_inner());
    json!({"$registry": registry.signatures().map(|(name, _)| name.to_owned()).collect::<Vec<_>>()})
});

/// A state snapshot held as an object.
#[derive(Clone)]
pub struct SnapshotObj(pub utopia_database::state::Snapshot);
php_object!(SnapshotObj, "State\\Snapshot", |s| super::objects::snapshot(&s.0));

/// A query builder held as an object (`{"$builder": true}`), with the
/// database that runs its statements (PHP's executor), if any.
#[derive(Clone)]
pub struct BuilderObj(pub Arc<std::sync::Mutex<utopia_database::hook::SqlBuilder>>, pub Option<Database>);
php_object!(BuilderObj, "Query\\Builder", |_b| json!({"$builder": true}));

/// A schema builder held as an object (`{"$schema": true}`).
#[derive(Clone)]
pub struct SchemaObj(pub Arc<std::sync::Mutex<utopia_database::adapter::SqlSchema>>);
php_object!(SchemaObj, "Query\\Schema", |_s| json!({"$schema": true}));

fn this(db: &Database) -> Obj {
    object(DatabaseObj(db.clone()))
}

fn void(r: utopia_database::Result<()>) -> R<Obj> {
    r.map(|()| Obj::null()).map_err(Fail::Error)
}

/// What a callback returned, as the scope method returns it.
fn returned(r: Result<Option<Obj>, Error>) -> R<Obj> {
    match r {
        Ok(v) => Ok(v.unwrap_or_else(Obj::null)),
        Err(e) => Err(Fail::Error(e)),
    }
}

/// An `onNext` callback of the bulk writes: the document and the one it
/// replaced, encoded.
struct Next(Cb);

#[async_trait::async_trait]
impl Callback<(Document, Option<Document>)> for Next {
    async fn call(&mut self, (document, old): (Document, Option<Document>)) -> utopia_database::Result<()> {
        let old = old.map(|d| codec::encode_document(&d)).unwrap_or(Json::Null);
        self.0.invoke(vec![codec::encode_document(&document), old]).await.map(|_| ())
    }
}

#[async_trait::async_trait]
impl Callback<Document> for Next {
    async fn call(&mut self, document: Document) -> utopia_database::Result<()> {
        let encoded = codec::encode_document(&document);
        self.0.invoke(vec![encoded.clone(), encoded]).await.map(|_| ())
    }
}

/// A `resolve` callback of the authorize methods: it runs its steps
/// synchronously (`$return` only).
fn resolve(cb: Option<Cb>) -> Option<dbmod::Resolve> {
    let cb = cb?;
    Some(Arc::new(move |collection: &str, id: &str| {
        let call = cb.invoke(vec![Json::String(collection.to_owned()), Json::String(id.to_owned())]);
        match call.now_or_never() {
            Some(Ok(Some(obj))) => args::obj_document(obj).ok(),
            _ => None,
        }
    }))
}

/// The collection of a parameter typed `Document $collection` that the
/// Rust method takes as a `Collection`.
fn collection_param(a: &Args, key: &str) -> R<utopia_database::Collection> {
    a.collection(key)
}

/// Calls `method` (camel case) of `db`.
pub async fn invoke(db: &Database, method: &str, a: &Args) -> R<Obj> {
    let calls = Calls::new(&a.cx);
    let result = call(db, method, a, &calls).await;
    calls.finish(result)
}

async fn call(db: &Database, method: &str, a: &Args, calls: &Calls) -> R<Obj> {
    if let Some(obj) = section_settings(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_hooks_and_connection(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_scopes(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_databases(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_collections(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_attributes(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_indexes(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_relationships(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_documents_writes(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_documents_reads(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_encoding(db, method, a, calls).await? {
        return Ok(obj);
    }
    if let Some(obj) = section_raw_statements(db, method, a, calls).await? {
        return Ok(obj);
    }
    fault(format!("Database has no method `{method}`"))
}

/// The `Settings` methods (`None`: not one of them).
fn section_settings<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "getAdapter" => object(AdapterObj(db.adapter().clone())),
            "getAuthorization" => object(AuthorizationObj(db.authorization())),
            "setAuthorization" => {
                db.set_authorization(authorization(a, "authorization")?);
                this(db)
            }
            "setDatabase" => {
                db.set_database(&s("name")?);
                this(db)
            }
            "getDatabase" => db.database().into_obj(),
            "setNamespace" => {
                db.set_namespace(&s("namespace")?);
                this(db)
            }
            "getNamespace" => db.namespace().into_obj(),
            "getIdAttributeType" => super::enums::obj(db.id_attribute_type()),
            "profile" => object(db.profile()),
            "setSchemaless" => {
                db.set_schemaless(a.bool("schemaless")?)?;
                this(db)
            }
            "setFilters" => {
                let registry = registry(a, "filters")?;
                db.set_filters(registry);
                this(db)
            }
            "getFilters" => object(RegistryObj(db.filters())),
            "setQueryCache" => {
                db.set_query_cache(query_cache(a, "queryCache").await?);
                this(db)
            }
            "getQueryCache" => db.query_cache().map(|q| object(QueryCacheObj(q))).into_obj(),
            "setProfiling" => {
                db.set_profiling(a.bool("profiling")?);
                this(db)
            }
            "isProfiling" => db.is_profiling().into_obj(),
            "getProfiler" => db.profiler().map(|p| object(ProfilerObj(p))).into_obj(),
            "setCache" => {
                db.set_cache(cache(a, "cache").await?);
                this(db)
            }
            "getCache" => object(CacheObj(db.cache())),
            "setCacheName" => {
                db.set_cache_name(&s("name")?);
                this(db)
            }
            "getCacheName" => db.cache_name().into_obj(),
            "setCacheWriterTimeout" => {
                db.set_cache_writer_timeout(a.int("seconds")?);
                this(db)
            }
            "setSharedTables" => {
                db.set_shared_tables(a.bool("sharedTables")?);
                this(db)
            }
            "hasSharedTables" => db.shared_tables().into_obj(),
            "setTenant" => {
                db.set_tenant(a.value("tenant")?);
                this(db)
            }
            "getTenant" => db.tenant().into_obj(),
            "setTenantPerDocument" => {
                db.set_tenant_per_document(a.bool("enabled")?);
                this(db)
            }
            "isTenantPerDocument" => db.tenant_per_document().into_obj(),
            "setTimeout" => {
                db.set_timeout(a.int("milliseconds")?, a.enum_::<Event>("event")?.unwrap_or(Event::All))?;
                this(db)
            }
            "clearTimeout" => void(db.clear_timeout(a.enum_::<Event>("event")?.unwrap_or(Event::All)))?,
            "setPreserveDates" => {
                db.set_preserve_dates(a.bool("preserve")?);
                this(db)
            }
            "isPreservingDates" => db.is_preserving_dates().into_obj(),
            "isDroppingUnknownAttributes" => db.is_dropping_unknown_attributes().into_obj(),
            "setDropUnknownAttributes" => {
                db.set_drop_unknown_attributes(a.bool("drop")?);
                this(db)
            }
            "setPreserveSequence" => {
                db.set_preserve_sequence(a.bool("preserve")?);
                this(db)
            }
            "isPreservingSequence" => db.is_preserving_sequence().into_obj(),
            "setCheckGrants" => {
                db.set_check_grants(a.bool("check")?);
                this(db)
            }
            "isCheckingGrants" => db.is_checking_grants().into_obj(),
            "setMigrating" => {
                db.set_migrating(a.bool("migrating")?);
                this(db)
            }
            "isMigrating" => db.is_migrating().into_obj(),
            "setMaxQueryValues" => {
                db.set_max_query_values(a.int("max")?);
                this(db)
            }
            "getMaxQueryValues" => db.max_query_values().into_obj(),
            "setGlobalCollections" => {
                db.set_global_collections(&a.strings("collections")?);
                this(db)
            }
            "getGlobalCollections" => strings(&db.global_collections()),
            "resetGlobalCollections" => {
                db.reset_global_collections();
                Obj::null()
            }
            "setDocumentType" => {
                let class = s("className")?;
                check_document_class(&class)?;
                db.set_document_type(&s("collection")?, &class);
                this(db)
            }
            "getDocumentType" => db.document_type(&s("collection")?).into_obj(),
            "clearDocumentType" => {
                db.clear_document_type(&s("collection")?);
                Obj::null()
            }
            "clearDocumentTypes" => {
                db.clear_document_types();
                Obj::null()
            }
            "setLocks" => {
                db.set_locks(a.bool("locks")?);
                this(db)
            }
            "setValidation" => {
                db.set_validation(a.bool("validation")?);
                this(db)
            }
            "isValidating" => db.is_validating().into_obj(),
            "setFiltering" => {
                db.set_filtering(a.bool("filtering")?);
                this(db)
            }
            "isFiltering" => db.is_filtering().into_obj(),
            "setMetadata" => {
                db.set_metadata(&s("key")?, a.value("value")?);
                this(db)
            }
            "getMetadata" => {
                let mut out = utopia_database::Array::new();
                for (k, v) in db.metadata() {
                    out.set(utopia_database::value::ArrayKey::from(k.as_str()), v);
                }
                out.into_obj()
            }
            "resetMetadata" => {
                db.reset_metadata();
                Obj::null()
            }
            _ => return Ok(None),
        }))
    })
}

/// The `Hooks and connection` methods (`None`: not one of them).
fn section_hooks_and_connection<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "addHook" => {
                let hook = hook(a, "hook")?;
                db.add_hook(hook.hook)?;
                this(db)
            }
            "removeHook" => {
                match a.raw("hook") {
                    Some(Json::String(name)) => {
                        db.remove_hook_class(&fixtures::hook_class(name));
                    }
                    _ => {
                        let hook = hook(a, "hook")?;
                        db.remove_hook(&hook.hook);
                    }
                }
                this(db)
            }
            "removeTransform" => {
                db.remove_transform(&fixtures::hook_class(&s("name")?));
                this(db)
            }
            "getConnectionId" => ok(db.connection_id().await)?,
            "getHostname" => ok(db.hostname().await)?,
            "ping" => ok(db.ping().await)?,
            "reconnect" => void(db.reconnect().await)?,
            "getLimitForAttributes" => db.limit_for_attributes().into_obj(),
            "getLimitForIndexes" => db.limit_for_indexes().into_obj(),
            "getMaxUidLength" => db.max_uid_length().into_obj(),
            "internalAttributes" => objects(db.internal_attributes().to_vec()),
            "getSchemaAttributes" => objects(db.schema_attributes(&s("collection")?).await?),
            "getSchemaIndexes" => objects(db.schema_indexes(&s("collection")?).await?),
            "snapshot" => object(SnapshotObj(db.snapshot())),
            "getCacheBaseKeys" => {
                let (a1, a2) = db.cache_base_keys(&s("collectionId")?, a.opt_string("documentId")?.as_deref()).await?;
                strings(&[a1, a2])
            }
            "getCacheKeys" => {
                let (a1, a2, a3) = db
                    .cache_keys(&s("collectionId")?, a.opt_string("documentId")?.as_deref(), &a.strings("selects")?)
                    .await?;
                strings(&[a1, a2, a3])
            }
            "getQueryCacheKey" => {
                db.query_cache_key(&s("collectionId")?, a.opt_string("namespace")?.as_deref()).await?.into_obj()
            }
            "getQueryCacheField" => {
                let collection = match a.opt_document("collection")? {
                    Some(d) => Some(utopia_database::Collection::from_document(&d)?),
                    None => None,
                };
                db.query_cache_field(
                    collection.as_ref(),
                    &a.queries("queries")?,
                    &a.string_or("field", "documents")?,
                    a.enum_::<PermissionType>("forPermission")?.unwrap_or(PermissionType::Read),
                )?
                .into_obj()
            }

            _ => return Ok(None),
        }))
    })
}

/// The `Scopes` methods (`None`: not one of them).
fn section_scopes<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "withTenant" => {
                let cb = cb("callback")?;
                returned(db.with_tenant(a.value("tenant")?, cb.invoke(Vec::new())).await)?
            }
            "silent" => {
                let cb = cb("callback")?;
                match a.opt_strings("hooks")? {
                    Some(hooks) => returned(db.silent_hooks(&hooks, cb.invoke(Vec::new())).await)?,
                    None => returned(db.silent(cb.invoke(Vec::new())).await)?,
                }
            }
            "withPreserveDates" => {
                let cb = cb("callback")?;
                returned(db.with_preserve_dates(a.bool("preserve")?, cb.invoke(Vec::new())).await)?
            }
            "ignoreDuplicates" => {
                let cb = cb("callback")?;
                returned(db.ignore_duplicates(cb.invoke(Vec::new())).await)?
            }
            "withPreserveSequence" => {
                let cb = cb("callback")?;
                returned(db.with_preserve_sequence(a.bool("preserve")?, cb.invoke(Vec::new())).await)?
            }
            "withCheckGrants" => {
                let cb = cb("callback")?;
                returned(db.with_check_grants(a.bool("check")?, cb.invoke(Vec::new())).await)?
            }
            "withValidation" => {
                let cb = cb("callback")?;
                returned(db.with_validation(a.bool("validation")?, cb.invoke(Vec::new())).await)?
            }
            "skipValidation" => {
                let cb = cb("callback")?;
                returned(db.skip_validation(cb.invoke(Vec::new())).await)?
            }
            "skipQueryHooks" => {
                let cb = cb("callback")?;
                returned(db.skip_query_hooks(cb.invoke(Vec::new())).await)?
            }
            "withFiltering" => {
                let cb = cb("callback")?;
                let filters = a.opt_strings("filters")?;
                returned(db.with_filtering(a.bool("filtering")?, filters.as_deref(), cb.invoke(Vec::new())).await)?
            }
            "skipFilters" => {
                let cb = cb("callback")?;
                let filters = a.opt_strings("filters")?;
                returned(db.skip_filters(filters.as_deref(), cb.invoke(Vec::new())).await)?
            }
            "withRequestTimestamp" => {
                let cb = cb("callback")?;
                let timestamp = a.datetime("requestTimestamp")?;
                returned(db.with_request_timestamp(timestamp, cb.invoke(Vec::new())).await)?
            }
            "skipRelationships" => {
                let cb = cb("callback")?;
                returned(db.skip_relationships(cb.invoke(Vec::new())).await)?
            }
            "skipRelationshipsExistCheck" => {
                let cb = cb("callback")?;
                returned(db.skip_relationships_exist_check(cb.invoke(Vec::new())).await)?
            }
            "withSnapshot" => {
                let cb = cb("callback")?;
                let snapshot = a.req::<SnapshotObj>("snapshot")?;
                returned(db.with_snapshot(&snapshot.0, cb.invoke(Vec::new())).await)?
            }
            "withTransaction" => {
                let cb = cb("callback")?;
                returned(db.with_transaction(|| cb.invoke(Vec::new())).await)?
            }
            "withSavepoint" => {
                let cb = cb("callback")?;
                let fallback = cb_named(calls, a, "fallback")?;
                returned(
                    db.with_savepoint(
                        || cb.invoke(Vec::new()),
                        |error: Error| async move { fallback.invoke(vec![codec::error(&error)]).await },
                    )
                    .await,
                )?
            }
            "withCache" => {
                let cb = cb("callback")?;
                // `?string $hash = ''`: absent is the empty hash, `null` uncached.
                let hash = if a.has("hash") { a.opt_string("hash")? } else { Some(String::new()) };
                ok(db.with_cache(&s("key")?, || async { cb.value(Vec::new()).await }, hash.as_deref()).await)?
            }

            _ => return Ok(None),
        }))
    })
}

/// The `Databases` methods (`None`: not one of them).
fn section_databases<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "create" => ok(db.create(a.opt_string("database")?.as_deref()).await)?,
            "exists" => ok(db.exists(a.opt_string("database")?.as_deref()).await)?,
            "list" => ok(db.list().await)?,
            "update" => ok(db.update(&s("database")?, &s("new")?).await)?,
            "delete" => ok(db.delete(a.opt_string("database")?.as_deref()).await)?,
            "collectionExists" => {
                ok(db.collection_exists(&s("collection")?, a.opt_string("database")?.as_deref()).await)?
            }

            _ => return Ok(None),
        }))
    })
}

/// The `Collections` methods (`None`: not one of them).
fn section_collections<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "createCollection" => ok(db.create_collection(a.collection("collection")?).await)?,
            "updateCollection" => {
                ok(db.update_collection(&s("collection")?, a.req::<collection::Update>("update")?).await)?
            }
            "getCollection" => ok(db.get_collection(&s("collection")?).await)?,
            "findCollection" => ok(db.find_collection(&s("collection")?).await)?,
            "listCollections" => ok(db.list_collections(a.int_or("limit", 25)?, a.int_or("offset", 0)?).await)?,
            "getSizeOfCollection" => ok(db.size_of_collection(&s("collection")?).await)?,
            "getSizeOfCollectionOnDisk" => ok(db.size_of_collection_on_disk(&s("collection")?).await)?,
            "analyzeCollection" => ok(db.analyze_collection(&s("collection")?).await)?,
            "deleteCollection" => void(db.delete_collection(&s("collection")?).await)?,

            _ => return Ok(None),
        }))
    })
}

/// The `Attributes` methods (`None`: not one of them).
fn section_attributes<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "createAttribute" => {
                object(db.create_attribute(&s("collection")?, a.req::<Attribute>("attribute")?).await?)
            }
            "createAttributes" => {
                objects(db.create_attributes(&s("collection")?, a.list::<Attribute>("attributes")?).await?)
            }
            "updateAttribute" => {
                object(db.update_attribute(&s("collection")?, &s("key")?, a.req::<attribute::Update>("update")?).await?)
            }
            "checkAttribute" => ok(db.check_attribute(&s("collection")?, &a.req::<Attribute>("attribute")?).await)?,
            "deleteAttribute" => void(db.delete_attribute(&s("collection")?, &s("key")?).await)?,
            "renameAttribute" => void(db.rename_attribute(&s("collection")?, &s("old")?, &s("new")?).await)?,

            _ => return Ok(None),
        }))
    })
}

/// The `Indexes` methods (`None`: not one of them).
fn section_indexes<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "createIndex" => object(db.create_index(&s("collection")?, a.req::<Index>("index")?).await?),
            "createIndexes" => objects(db.create_indexes(&s("collection")?, a.list::<Index>("indexes")?).await?),
            "renameIndex" => void(db.rename_index(&s("collection")?, &s("old")?, &s("new")?).await)?,
            "deleteIndex" => void(db.delete_index(&s("collection")?, &s("key")?).await)?,

            _ => return Ok(None),
        }))
    })
}

/// The `Relationships` methods (`None`: not one of them).
fn section_relationships<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "createRelationship" => {
                object(db.create_relationship(&s("collection")?, a.req::<Relationship>("relationship")?).await?)
            }
            "updateRelationship" => object(
                db.update_relationship(&s("collection")?, &s("key")?, a.req::<relationship::Update>("update")?).await?,
            ),
            "deleteRelationship" => void(db.delete_relationship(&s("collection")?, &s("key")?).await)?,

            _ => return Ok(None),
        }))
    })
}

/// The `Documents writes` methods (`None`: not one of them).
fn section_documents_writes<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "getDocument" => ok(db
                .get_document(&s("collection")?, &s("id")?, a.queries("queries")?, a.bool_or("forUpdate", false)?)
                .await)?,
            "createDocument" => ok(db.create_document(&s("collection")?, a.document("document")?).await)?,
            "createDocuments" => {
                let mut next = calls.callback("onNext", a.raw("onNext"))?.map(Next);
                ok(db
                    .create_documents(
                        &s("collection")?,
                        a.documents("documents")?,
                        a.int_or("batchSize", dbmod::BATCH_SIZE)?,
                        next.as_mut().map(|n| n as &mut dyn Callback<(Document, Option<Document>)>),
                    )
                    .await)?
            }
            "updateDocument" => ok(db.update_document(&s("collection")?, &s("id")?, a.document("document")?).await)?,
            "updateDocuments" => {
                let mut next = calls.callback("onNext", a.raw("onNext"))?.map(Next);
                ok(db
                    .update_documents(
                        &s("collection")?,
                        a.document("updates")?,
                        a.queries("queries")?,
                        a.int_or("batchSize", dbmod::BATCH_SIZE)?,
                        next.as_mut().map(|n| n as &mut dyn Callback<(Document, Option<Document>)>),
                    )
                    .await)?
            }
            "upsertDocument" => ok(db.upsert_document(&s("collection")?, a.document("document")?).await)?,
            "upsertDocuments" => {
                let mut next = calls.callback("onNext", a.raw("onNext"))?.map(Next);
                let increase = a.opt_string("increase")?;
                ok(db
                    .upsert_documents(
                        &s("collection")?,
                        a.documents("documents")?,
                        a.int_or("batchSize", dbmod::BATCH_SIZE)?,
                        next.as_mut().map(|n| n as &mut dyn Callback<(Document, Option<Document>)>),
                        increase.as_deref(),
                    )
                    .await)?
            }
            "deleteDocument" => ok(db.delete_document(&s("collection")?, &s("id")?).await)?,
            "deleteDocuments" => {
                let mut next = calls.callback("onNext", a.raw("onNext"))?.map(Next);
                ok(db
                    .delete_documents(
                        &s("collection")?,
                        a.queries("queries")?,
                        a.int_or("batchSize", dbmod::BATCH_SIZE)?,
                        next.as_mut().map(|n| n as &mut dyn Callback<Document>),
                    )
                    .await)?
            }
            "increaseDocumentAttribute" => ok(db
                .increase_document_attribute(
                    &s("collection")?,
                    &s("id")?,
                    &s("attribute")?,
                    a.opt_value("value")?.unwrap_or(Value::Int(1)),
                    a.opt_value("max")?.filter(|v| !matches!(v, Value::Null)),
                )
                .await)?,
            "decreaseDocumentAttribute" => ok(db
                .decrease_document_attribute(
                    &s("collection")?,
                    &s("id")?,
                    &s("attribute")?,
                    a.opt_value("value")?.unwrap_or(Value::Int(1)),
                    a.opt_value("min")?.filter(|v| !matches!(v, Value::Null)),
                )
                .await)?,
            _ => return Ok(None),
        }))
    })
}

/// The `Documents reads` methods (`None`: not one of them).
fn section_documents_reads<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "find" => ok(db
                .find(
                    &s("collection")?,
                    a.queries("queries")?,
                    a.enum_::<PermissionType>("forPermission")?.unwrap_or(PermissionType::Read),
                )
                .await)?,
            "findOne" => ok(db.find_one(&s("collection")?, a.queries("queries")?).await)?,
            "count" => ok(db.count(&s("collection")?, a.queries("queries")?, a.opt_int("max")?).await)?,
            "sum" => ok(db.sum(&s("collection")?, &s("attribute")?, a.queries("queries")?, a.opt_int("max")?).await)?,
            "aggregate" => ok(db.aggregate(&s("collection")?, a.queries("queries")?).await)?,
            "cursor" => {
                let mut stream = db
                    .cursor(
                        &s("collection")?,
                        a.queries("queries")?,
                        a.int_or("batchSize", dbmod::CURSOR_BATCH_SIZE)?,
                        a.enum_::<PermissionType>("forPermission")?.unwrap_or(PermissionType::Read),
                    )
                    .await?;
                let mut out = Vec::new();
                while let Some(document) = stream.next().await {
                    out.push(document?);
                }
                out.into_obj()
            }
            "resolveCursor" => ok(db
                .resolve_cursor(
                    &s("collection")?,
                    &s("id")?,
                    a.req_enum::<CursorDirection>("direction")?,
                    a.queries("queries")?,
                )
                .await)?,
            "authorizeCreate" => {
                let r = resolve(calls.callback("resolve", a.raw("resolve"))?);
                void(db.authorize_create(&s("collection")?, &a.document("document")?, r).await)?
            }
            "authorizeUpdate" => {
                let r = resolve(calls.callback("resolve", a.raw("resolve"))?);
                void(db.authorize_update(&s("collection")?, &a.document("old")?, &a.document("document")?, r).await)?
            }
            "authorizeGrants" => {
                let r = resolve(calls.callback("resolve", a.raw("resolve"))?);
                void(db.authorize_grants(&s("collection")?, &a.document("document")?, r).await)?
            }
            "prepareCreate" => {
                ok(db.prepare_create(&collection_param(a, "collection")?, a.document("document")?).await)?
            }
            "createPrepared" => {
                let pairs = match a.raw("documents") {
                    Some(list) => codec::list(list)?
                        .into_iter()
                        .map(|pair| {
                            let items = codec::list(pair)?;
                            match items.as_slice() {
                                [c, d] => Ok((args::collection_of(&a.cx, c)?, args::document_of(&a.cx, d)?)),
                                _ => fault("createPrepared takes [collection, document] pairs"),
                            }
                        })
                        .collect::<R<Vec<_>>>()?,
                    None => Vec::new(),
                };
                void(db.create_prepared(pairs).await)?
            }
            "applySelectFiltersToDocuments" => {
                let mut documents = a.documents("documents")?;
                db.apply_select_filters_to_documents(&mut documents, &a.queries("selectQueries")?);
                Obj::null()
            }
            "purgeCachedCollection" => void(db.purge_cached_collection(&s("collection")?).await)?,
            "purgeCachedDocument" => void(db.purge_cached_document(&s("collection")?, &s("id")?).await)?,
            "purgeCachedQueries" => {
                ok(db.purge_cached_queries(&s("collection")?, a.opt_string("namespace")?.as_deref()).await)?
            }

            _ => return Ok(None),
        }))
    })
}

/// The `Encoding` methods (`None`: not one of them).
fn section_encoding<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "encode" => ok(db
                .encode(&collection_param(a, "collection")?, a.document("document")?, a.bool_or("applyDefaults", true)?)
                .await)?,
            "decode" => ok(db
                .decode(&collection_param(a, "collection")?, a.document("document")?, &a.strings("selections")?)
                .await)?,
            "casting" => ok(db.casting(&collection_param(a, "collection")?, a.document("document")?))?,
            "convertQueries" => {
                let joined = match a.raw("joinedCollections") {
                    Some(Json::Object(map)) => map
                        .iter()
                        .map(|(alias, c)| Ok((alias.clone(), args::collection_of(&a.cx, c)?)))
                        .collect::<R<Vec<_>>>()?,
                    _ => Vec::new(),
                };
                let converted = db.convert_queries(&collection_param(a, "collection")?, a.queries("queries")?, &joined);
                // PHP converts each query object in place: the stored ones
                // see the change.
                if let (Ok(queries), Some(Json::Array(sent))) = (&converted, a.raw("queries"))
                    && queries.len() == sent.len()
                {
                    for (query, json) in queries.iter().zip(sent) {
                        store_back(a, json, query);
                    }
                }
                ok(converted)?
            }
            "convertQuery" => {
                let converted = db.convert_query(&collection_param(a, "collection")?, a.query("query")?);
                if let (Ok(query), Some(json)) = (&converted, a.raw("query")) {
                    store_back(a, json, query);
                }
                ok(converted)?
            }

            _ => return Ok(None),
        }))
    })
}

/// A query converted in place: `{"$obj": <name>}` now names the converted
/// query, as the PHP object the library changed.
fn store_back(a: &Args, json: &Json, query: &Query) {
    if let Some(name) = json.as_object().filter(|o| o.len() == 1).and_then(|o| o.get("$obj")).and_then(Json::as_str) {
        a.cx.store(name, query.clone().into_obj());
    }
}

/// The `Raw statements` methods (`None`: not one of them).
fn section_raw_statements<'a>(
    db: &'a Database,
    method: &'a str,
    a: &'a Args,
    calls: &'a Calls,
) -> BoxFuture<'a, R<Option<Obj>>> {
    Box::pin(async move {
        let s = |k: &str| a.string(k);
        let cb = |k: &str| calls.required(k, a.raw(k));
        let _ = (&s, &cb);
        Ok(Some(match method {
            "from" => object(BuilderObj(
                Arc::new(std::sync::Mutex::new(db.from(&s("collection")?, &a.string_or("alias", "")?)?)),
                Some(db.clone()),
            )),
            "schema" => object(SchemaObj(Arc::new(std::sync::Mutex::new(db.schema()?)))),
            "query" => ok(db.query(&statement(a, "query")?).await)?,
            "mutate" => ok(db.mutate(&statement(a, "query")?).await)?,

            _ => return Ok(None),
        }))
    })
}

/// The document classes the PHP driver has loaded (the e2e suite's custom
/// document types); PHP's `setDocumentType()` refuses a class that does not
/// exist or does not extend `Document`, which Rust, without classes, checks
/// here against what the PHP process holds.
const DOCUMENT_CLASSES: [&str; 4] = [
    "Utopia\\Database\\Document",
    "Utopia\\Database\\Collection",
    "Tests\\E2E\\Adapter\\Support\\User",
    "Tests\\E2E\\Adapter\\Support\\Post",
];

/// `setDocumentType()`'s `class_exists()` and `is_subclass_of()` checks,
/// for the PHP driver's classes (`stdClass` and the library's other
/// classes exist and are no documents).
fn check_document_class(class: &str) -> R<()> {
    let class = class.trim_start_matches('\\');
    if DOCUMENT_CLASSES.contains(&class) && class != "Utopia\\Database\\Document" {
        return Ok(());
    }
    let exists = class == "stdClass" || class.starts_with("Utopia\\") || DOCUMENT_CLASSES.contains(&class);
    let message = if exists {
        format!("Class {class} must extend Utopia\\Database\\Document")
    } else {
        format!("Class {class} does not exist")
    };
    Err(Fail::Error(Error::new(utopia_database::Kind::Database, message)))
}

fn cb_named(calls: &Calls, a: &Args, name: &str) -> R<Cb> {
    calls.required(name, a.raw(name))
}

/// An `Authorization` parameter: a stored one, a database handle (that
/// database's), or `{"roles": [...], "status"?: bool}` (a new one).
pub fn authorization(a: &Args, key: &str) -> R<Arc<utopia_database::Authorization>> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        if let Some(auth) = obj.downcast_ref::<AuthorizationObj>() {
            return Ok(auth.0.clone());
        }
        if let Some(db) = obj.downcast_ref::<DatabaseObj>() {
            return Ok(db.0.authorization());
        }
        return fault(format!("`{key}` is not an authorization"));
    }
    if j.get("$handle").is_some() {
        return Ok(a.cx.db_of(j)?.database.authorization());
    }
    let spec = Args::new(&a.cx, j)?;
    let authorization = utopia_database::Authorization::new(true);
    for role in spec.strings("roles")? {
        authorization.add_role(&role);
    }
    if spec.has("status") {
        authorization.set_status(spec.bool_or("status", true)?);
    }
    Ok(Arc::new(authorization))
}

/// A `Cache` parameter: a stored cache or a cache kind.
pub async fn cache(a: &Args, key: &str) -> R<Arc<utopia_cache::Cache>> {
    if let Some(j) = a.raw(key)
        && let Some(obj) = args::stored_obj(&a.cx, j)?
    {
        return Ok(args::downcast::<CacheObj>(&obj)?.0);
    }
    super::open::cache(&a.cx, &a.string_or(key, "memory")?, false).await
}

/// A query cache parameter (`{"cache": <kind>|"database", "slots"?: n,
/// "as"?}` or a stored one); `None` for `null`.
pub async fn query_cache(a: &Args, key: &str) -> R<Option<Arc<utopia_database::cache::Query>>> {
    let Some(j) = a.raw(key).filter(|j| !j.is_null()) else {
        return Ok(None);
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        return Ok(Some(args::downcast::<QueryCacheObj>(&obj)?.0));
    }
    let spec = Args::new(&a.cx, j)?;
    let kind = spec.string_or("cache", "database")?;
    let cache = if kind == "database" {
        match a.cx.current() {
            Some(db) => db.database.cache(),
            None => return fault("no database"),
        }
    } else {
        super::open::cache(&a.cx, &kind, false).await?
    };
    let query_cache = Arc::new(utopia_database::cache::Query::new(cache, spec.int_or("slots", 1024)?)?);
    if let Some(name) = spec.opt_string("as")? {
        a.cx.store(&name, object(QueryCacheObj(query_cache.clone())));
    }
    Ok(Some(query_cache))
}

/// A hook parameter: a stored hook or a hook fixture spec (stored under
/// its `as`).
pub fn hook(a: &Args, key: &str) -> R<HookObj> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        return args::downcast::<HookObj>(&obj);
    }
    let hook = fixtures::make_hook(j)?;
    if let Some(name) = j.get("as").and_then(Json::as_str) {
        a.cx.store(name, object(hook.clone()));
    }
    Ok(hook)
}

/// A codec parameter: a stored codec or a codec fixture spec.
pub fn codec_param(a: &Args, key: &str) -> R<CodecObj> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        return args::downcast::<CodecObj>(&obj);
    }
    let codec = fixtures::make_codec(j)?;
    if let Some(name) = j.get("as").and_then(Json::as_str) {
        a.cx.store(name, object(codec.clone()));
    }
    Ok(codec)
}

/// A `Registry` parameter: a stored one, or a list of codec specs.
pub fn registry(a: &Args, key: &str) -> R<Arc<std::sync::RwLock<utopia_database::filter::Registry>>> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        return Ok(args::downcast::<RegistryObj>(&obj)?.0);
    }
    let mut registry = utopia_database::filter::Registry::new();
    for spec in codec::list(j)? {
        let codec = match args::stored_obj(&a.cx, spec)? {
            Some(obj) => args::downcast::<CodecObj>(&obj)?,
            None => fixtures::make_codec(spec)?,
        };
        registry.register(codec.0)?;
    }
    Ok(Arc::new(std::sync::RwLock::new(registry)))
}

/// A raw statement parameter (`Builder|Statement`): a stored statement or
/// builder, a `Query\Builder\Statement` value or `{"query", "bindings"}`.
pub fn statement(a: &Args, key: &str) -> R<utopia_query::builder::Statement> {
    let Some(j) = a.raw(key) else {
        return fault(format!("missing argument `{key}`"));
    };
    if let Some(obj) = args::stored_obj(&a.cx, j)? {
        return super::objects::statement_of(&obj);
    }
    if let (Some(Json::String(class)), Some(fields)) = (j.get("$value"), j.get("fields"))
        && class == "Query\\Builder\\Statement"
    {
        return super::objects::decode_statement(&a.cx, fields);
    }
    let spec = Args::new(&a.cx, j)?;
    let bindings = utopia_database::value::to_query_array(&spec.array("bindings")?);
    Ok(utopia_query::builder::Statement::new(
        spec.string_or("query", "")?,
        bindings.into_iter().map(|(_, v)| v).collect(),
    ))
}

/// `Database::collectionDefinition()` and `internalAttributesFor()`.
pub fn call_static(method: &str, a: &Args) -> R<Obj> {
    Ok(match method {
        "collectionDefinition" => dbmod::collection_definition().into_obj(),
        "internalAttributesFor" => objects(dbmod::internal_attributes(a.bool("sharedTables")?).to_vec()),
        "addFilter" => {
            // The filter's callbacks run later, on calls of their own; this
            // call reports them, never called yet (`{"encode": [], "decode": []}`).
            let kept = Calls::new(&a.cx);
            let encode = kept.required("encode", a.raw("encode"))?;
            let decode = kept.required("decode", a.raw("decode"))?;
            Database::add_filter(&a.string("name")?, Arc::new(fixtures::CallbackFilter { encode, decode }));
            let reported = Calls::new(&a.cx);
            reported.required("encode", a.raw("encode"))?;
            reported.required("decode", a.raw("decode"))?;
            return reported.finish(Ok(Obj::null()));
        }
        _ => return fault(format!("Database has no static method `{method}`")),
    })
}

/// `{"$database": true}` for a database, else the value.
pub fn database_marker() -> Json {
    json!({"$database": true})
}

/// Suppresses unused-import warnings for types only some builds use.
#[allow(dead_code)]
fn unused(_: Query, _: Str, _: LimitsObj) {}
