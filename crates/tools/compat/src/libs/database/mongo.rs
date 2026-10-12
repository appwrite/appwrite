//! Area `mongo`: the MongoDB adapter (`utopia_database::adapter::mongo`),
//! the twin of `tests/compat/database/ops/mongo.php`, and `db.open`'s
//! `engine: "mongo"`.

use std::sync::Arc;

use serde_json::{Map, Value as Json, json};
use utopia_database::adapter::mongo::Mongo;
use utopia_database::adapter::{Adapter, FindOptions};
use utopia_database::{Authorization, Database};
use utopia_mongo::{Client, Config};

use super::core::args::{self, Args, Calls, Fail, R, encode, fault};
use crate::adapter::{Fault, OpResult, Session};

/// Operations of this area.
pub const OPS: &[&str] = &["mongo.adapter", "mongo.permission_strings", "mongo.fuzz_find", "mongo.fuzz_roundtrip"];

/// `mongodb://user:password@host:port` of service `name`.
fn url(session: &Session, name: &str) -> Result<(String, String, String, u16), Fault> {
    let raw = session.service(name)?;
    let rest = raw.strip_prefix("mongodb://").ok_or_else(|| Fault::new(format!("invalid `{name}` URL")))?;
    let rest = rest.split(['/', '?']).next().unwrap_or("");
    let (auth, host) = rest.rsplit_once('@').unwrap_or(("", rest));
    let (user, pass) = auth.split_once(':').unwrap_or((auth, ""));
    let (host, port) = host.rsplit_once(':').unwrap_or((host, "27017"));
    Ok((user.to_owned(), pass.to_owned(), host.to_owned(), port.parse().map_err(|_| Fault::new("invalid port"))?))
}

/// The benchmarks' adapter: a client of service `database-mongo` on
/// `database`.
pub(super) async fn bench_adapter(session: &Session, database: &str) -> Result<Mongo, Fault> {
    let (user, pass, host, port) = url(session, "database-mongo")?;
    let config = Config::new(database, host, i64::from(port), user, pass);
    let client = Client::new(config).map_err(|e| Fault::new(e.to_string()))?;
    Mongo::new(Arc::new(client)).await.map_err(|e| Fault::new(e.to_string()))
}

/// The adapter `db.open` with `engine: "mongo"` builds: a client on
/// service `database-mongo` whose database is `database` (default: the
/// case namespace), reporting PHP's host (which cache keys carry) and
/// dialling this runtime's address.
pub(super) async fn open(a: &Args<'_>, session: &mut Session) -> R<Mongo> {
    let (user, pass, host, port) = url(session, "database-mongo")?;
    let (php_host, php_port) = match url(session, "database-mongo@php") {
        Ok((_, _, h, p)) => (h, p),
        Err(_) => (host.clone(), port),
    };
    let ns = session.ns.clone();
    let mut config = Config::new(a.string_or("database", &ns)?, php_host, i64::from(php_port), user, pass);
    config.address = Some((host, port));
    let client = Client::new(config).map_err(utopia_database::adapter::mongo::from_client)?;
    Ok(Mongo::new(Arc::new(client)).await?)
}

/// The fuzz profiles' adapters, one per namespace (PHP keeps them in a
/// static variable of the operation).
static FUZZ: std::sync::OnceLock<tokio::sync::Mutex<std::collections::HashMap<String, Arc<Mongo>>>> =
    std::sync::OnceLock::new();

/// The fuzz profiles' adapter on MongoDB: database `${ns}`, seeded as
/// `adapter.fuzz_*` seed theirs.
async fn fuzz_adapter(session: &Session) -> Result<Result<Arc<Mongo>, utopia_database::Error>, Fault> {
    let mut map = FUZZ.get_or_init(Default::default).lock().await;
    if let Some(db) = map.get(&session.ns) {
        return Ok(Ok(db.clone()));
    }
    let (user, pass, host, port) = url(session, "database-mongo")?;
    let client = Client::new(Config::new(session.ns.as_str(), host, i64::from(port), user, pass))
        .map_err(|e| Fault::new(e.to_string()))?;
    let db = match Mongo::new(Arc::new(client)).await {
        Ok(db) => db,
        Err(e) => return Ok(Err(e)),
    };
    if let Err(e) = super::adapter::fuzz_seed(&db, &session.ns).await {
        return Ok(Err(e));
    }
    let db = Arc::new(db);
    map.insert(session.ns.clone(), db.clone());
    Ok(Ok(db))
}

pub async fn call(op: &str, json: &Json, session: &mut Session) -> OpResult {
    if op == "mongo.fuzz_find" || op == "mongo.fuzz_roundtrip" {
        let db = match fuzz_adapter(session).await? {
            Ok(db) => db,
            Err(e) => return Ok(super::codec::error(&e)),
        };
        return if op == "mongo.fuzz_find" {
            super::adapter::fuzz_find(&*db, json).await
        } else {
            super::adapter::fuzz_roundtrip(&*db, json).await
        };
    }
    args::enter(session);
    let result = match op {
        "mongo.adapter" => adapter(json, session).await,
        "mongo.permission_strings" => (|| {
            let a = Args::new(json);
            let authorization = Authorization::new();
            authorization.enable();
            authorization.clean_roles();
            for role in a.strings("roles")? {
                authorization.add_role(&role);
            }
            Ok(args::strings(&Mongo::permission_strings_of(&authorization, &a.string_or("type", "")?)))
        })(),
        _ => return Err(Fault::new(format!("database: unknown operation `{op}`"))),
    };
    args::outcome(result)
}

/// `$db->getAdapter()->{method}(...args)` on a MongoDB database.
async fn adapter(json: &Json, session: &mut Session) -> R<Json> {
    let a = Args::new(json);
    let method = a.string_or("method", "")?;
    let empty = Json::Object(Map::new());
    let raw_args = a.raw("args").filter(|j| !j.is_null()).unwrap_or(&empty).clone();
    let args = Args::new(&raw_args);
    let handle = args::db_handle(json, session)?;
    let Some(m) = handle.mongo.clone() else { return fault("mongo.adapter: the database is not on MongoDB") };
    let collection_id = || args.string("collection");
    let boolean = |b: bool| -> R<Json> { Ok(Json::Bool(b)) };
    match method.as_str() {
        "getHostname" => Ok(Json::String(m.hostname())),
        "getDriver" => Ok(json!({"$client": true})),
        "setTimeout" => {
            m.set_timeout(args.int("milliseconds")?, &args.string_or("event", Database::EVENT_ALL)?)?;
            Ok(Json::Null)
        }
        "clearTimeout" => {
            m.clear_timeout(&args.string("event")?);
            Ok(Json::Null)
        }
        "withTransaction" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", args.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = utopia_database::adapter::with_transaction(&*m, || cb.invoke(Vec::new())).await;
            let r = r.map(|v| encode(&v.unwrap_or(utopia_database::Value::Null))).map_err(Fail::from);
            calls.finish(r)
        }
        "startTransaction" => boolean(m.start_transaction().await?),
        "commitTransaction" => boolean(m.commit_transaction().await?),
        "rollbackTransaction" => boolean(m.rollback_transaction().await?),
        "ping" => boolean(m.ping().await?),
        "reconnect" => {
            m.reconnect().await?;
            Ok(Json::Null)
        }
        "create" => boolean(m.create(&args.string("name")?).await?),
        "exists" => boolean(m.exists(&args.string("database")?, args.opt_string("collection")?.as_deref()).await?),
        "list" => Ok(Json::Array(m.database_list().await?.iter().map(encode).collect())),
        "delete" => boolean(m.delete(&args.string("name")?).await?),
        "createCollection" => boolean(
            m.create_collection(&args.string("name")?, &args.documents("attributes")?, &args.documents("indexes")?)
                .await?,
        ),
        "listCollections" => Ok(Json::Array(m.list_collections().await?.iter().map(encode).collect())),
        "getSizeOfCollectionOnDisk" => Ok(Json::from(m.size_of_collection_on_disk(&collection_id()?).await?)),
        "getSizeOfCollection" => Ok(Json::from(m.size_of_collection(&collection_id()?).await?)),
        "deleteCollection" => boolean(m.delete_collection(&args.string("id")?).await?),
        "analyzeCollection" => boolean(m.analyze_collection(&collection_id()?).await?),
        "createAttribute" => boolean(
            m.create_attribute(
                &collection_id()?,
                &args.string("id")?,
                &args.string("type")?,
                args.int("size")?,
                args.bool_or("signed", true)?,
                args.bool_or("array", false)?,
                args.bool_or("required", false)?,
            )
            .await?,
        ),
        "createAttributes" => {
            let attributes: Vec<_> = args
                .array("attributes")?
                .values()
                .map(|v| match v {
                    utopia_database::Value::Array(a) => a.clone(),
                    _ => Default::default(),
                })
                .collect();
            boolean(m.create_attributes(&collection_id()?, &attributes).await?)
        }
        "deleteAttribute" => boolean(m.delete_attribute(&collection_id()?, &args.string("id")?).await?),
        "renameAttribute" => {
            boolean(m.rename_attribute(&collection_id()?, &args.string("id")?, &args.string("name")?).await?)
        }
        "updateAttribute" => boolean(
            m.update_attribute(
                &collection_id()?,
                &args.string("id")?,
                &args.string("type")?,
                args.int("size")?,
                args.bool_or("signed", true)?,
                args.bool_or("array", false)?,
                args.opt_string("newKey")?.as_deref(),
                args.bool_or("required", false)?,
            )
            .await?,
        ),
        "createRelationship" => boolean(
            m.create_relationship(
                &collection_id()?,
                &args.string("relatedCollection")?,
                &args.string("type")?,
                args.bool_or("twoWay", false)?,
                &args.string_or("id", "")?,
                &args.string_or("twoWayKey", "")?,
            )
            .await?,
        ),
        "updateRelationship" => boolean(
            m.update_relationship(
                &collection_id()?,
                &args.string("relatedCollection")?,
                &args.string("type")?,
                args.bool("twoWay")?,
                &args.string("key")?,
                &args.string("twoWayKey")?,
                &args.string("side")?,
                args.opt_string("newKey")?.as_deref(),
                args.opt_string("newTwoWayKey")?.as_deref(),
            )
            .await?,
        ),
        "deleteRelationship" => boolean(
            m.delete_relationship(
                &collection_id()?,
                &args.string("relatedCollection")?,
                &args.string("type")?,
                args.bool("twoWay")?,
                &args.string("key")?,
                &args.string("twoWayKey")?,
                &args.string("side")?,
            )
            .await?,
        ),
        "createIndex" => {
            let types: Vec<(String, String)> = args
                .array("indexAttributeTypes")
                .unwrap_or_default()
                .iter()
                .map(|(k, v)| (key_text(k), utopia_database::value::to_text(v)))
                .collect();
            let list = |key: &str| -> R<Vec<utopia_database::Value>> {
                Ok(if args.given(key) { args.array(key)?.values().cloned().collect() } else { Vec::new() })
            };
            boolean(
                m.create_index(
                    &collection_id()?,
                    &args.string("id")?,
                    &args.string("type")?,
                    &args.strings("attributes")?,
                    &list("lengths")?,
                    &list("orders")?,
                    &types,
                    &if args.given("collation") { args.strings("collation")? } else { Vec::new() },
                    args.int_or("ttl", 1)?,
                )
                .await?,
            )
        }
        "renameIndex" => boolean(m.rename_index(&collection_id()?, &args.string("old")?, &args.string("new")?).await?),
        "deleteIndex" => boolean(m.delete_index(&collection_id()?, &args.string("id")?).await?),
        "getDocument" => Ok(args::document(
            &m.get_document(
                &args.document("collection")?,
                &args.string("id")?,
                &if args.given("queries") { args.queries("queries")? } else { Vec::new() },
                args.bool_or("forUpdate", false)?,
            )
            .await?,
        )),
        "createDocument" => {
            Ok(args::document(&m.create_document(&args.document("collection")?, args.document("document")?).await?))
        }
        "castingAfter" => {
            Ok(args::document(&m.casting_after(&args.document("collection")?, args.document("document")?)?))
        }
        "castingBefore" => {
            Ok(args::document(&m.casting_before(&args.document("collection")?, args.document("document")?)?))
        }
        "createDocuments" => {
            Ok(args::documents(&m.create_documents(&args.document("collection")?, args.documents("documents")?).await?))
        }
        "updateDocument" => Ok(args::document(
            &m.update_document(
                &args.document("collection")?,
                &args.string("id")?,
                args.document("document")?,
                args.bool_or("skipPermissions", false)?,
            )
            .await?,
        )),
        "updateDocuments" => Ok(Json::from(
            m.update_documents(
                &args.document("collection")?,
                &args.document("updates")?,
                &args.documents("documents")?,
            )
            .await?,
        )),
        // Change objects do not cross the wire: only an empty list does
        // (the database's upserts exercise the rest).
        "upsertDocuments" => {
            if !args.array("changes")?.is_empty() {
                return fault("mongo.adapter: upsertDocuments takes no changes over the wire");
            }
            let collection = args.document("collection")?;
            Ok(args::documents(&m.upsert_documents(&collection, &args.string_or("attribute", "")?, Vec::new()).await?))
        }
        "getSequences" => Ok(args::documents(&m.get_sequences(&collection_id()?, args.documents("documents")?).await?)),
        "increaseDocumentAttribute" => boolean(
            m.increase_document_attribute(
                &collection_id()?,
                &args.string("id")?,
                &args.string("attribute")?,
                &args.value("value")?,
                &args.string("updatedAt")?,
                args.opt_value("min")?.as_ref(),
                args.opt_value("max")?.as_ref(),
            )
            .await?,
        ),
        "deleteDocument" => boolean(m.delete_document(&collection_id()?, &args.string("id")?).await?),
        "deleteDocuments" => {
            let sequences: Vec<_> = args.array("sequences")?.values().cloned().collect();
            let permissions = if args.given("permissionIds") { args.strings("permissionIds")? } else { Vec::new() };
            Ok(Json::from(m.delete_documents(&collection_id()?, &sequences, &permissions).await?))
        }
        "find" => {
            let order_attributes =
                if args.given("orderAttributes") { args.strings("orderAttributes")? } else { Vec::new() };
            let order_types = if args.given("orderTypes") { args.strings("orderTypes")? } else { Vec::new() };
            let cursor = if args.given("cursor") { args.array("cursor")? } else { Default::default() };
            let direction = args.string_or("cursorDirection", Database::CURSOR_AFTER)?;
            let permission = args.string_or("forPermission", Database::PERMISSION_READ)?;
            let limit = if args.has("limit") { args.opt_int("limit")? } else { Some(25) };
            let options = FindOptions {
                limit,
                offset: args.opt_int("offset")?,
                order_attributes: &order_attributes,
                order_types: &order_types,
                cursor: &cursor,
                cursor_direction: &direction,
                for_permission: &permission,
            };
            let queries = if args.given("queries") { args.queries("queries")? } else { Vec::new() };
            Ok(args::documents(&m.find(&args.document("collection")?, &queries, options).await?))
        }
        "count" => {
            let queries = if args.given("queries") { args.queries("queries")? } else { Vec::new() };
            Ok(Json::from(m.count(&args.document("collection")?, &queries, args.opt_int("max")?).await?))
        }
        "sum" => {
            let queries = if args.given("queries") { args.queries("queries")? } else { Vec::new() };
            Ok(encode(
                &m.sum(&args.document("collection")?, &args.string("attribute")?, &queries, args.opt_int("max")?)
                    .await?,
            ))
        }
        "setUTCDatetime" => Ok(encode(&m.set_utc_datetime(&args.string("value")?)?)),
        "setSupportForAttributes" => boolean(m.set_support_for_attributes(args.bool("support")?)),
        "getConnectionId" => Ok(Json::String(m.connection_id().await?)),
        "getSchemaAttributes" => Ok(args::documents(&m.schema_attributes(&collection_id()?).await?)),
        "getSchemaIndexes" => Ok(args::documents(&m.schema_indexes(&collection_id()?).await?)),
        "getTenantFilters" => {
            let tenants: Vec<_> =
                if args.given("tenants") { args.array("tenants")?.values().cloned().collect() } else { Vec::new() };
            Ok(encode(&m.tenant_filters(&collection_id()?, &tenants)))
        }
        "decodePoint" => Ok(encode(&m.decode_point(&args.string("wkb")?.into_bytes())?)),
        "decodeLinestring" => Ok(encode(&m.decode_linestring(&args.string("wkb")?.into_bytes())?)),
        "decodePolygon" => Ok(encode(&m.decode_polygon(&args.string("wkb")?.into_bytes())?)),
        "getTenantQuery" => Ok(Json::String(m.tenant_query(&collection_id()?, &args.string_or("alias", "")?))),
        "getColumnType" => Ok(Json::String(m.column_type(
            &args.string("type")?,
            args.int("size")?,
            args.bool_or("signed", true)?,
            args.bool_or("array", false)?,
            args.bool_or("required", false)?,
        )?)),
        _ => super::core::adapter_call(&a, &handle.database).await,
    }
}

fn key_text(k: utopia_database::value::KeyRef<'_>) -> String {
    match k {
        utopia_database::value::KeyRef::Int(i) => i.to_string(),
        utopia_database::value::KeyRef::Str(s) => String::from_utf8_lossy(s).into_owned(),
    }
}
