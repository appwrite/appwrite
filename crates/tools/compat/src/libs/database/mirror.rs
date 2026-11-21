//! Mirrors (`Utopia\Database\Mirror`): `db.open` with `mirror`
//! (`Core::mirror`), `db.mirror`, `database.on_error`, the methods only a
//! mirror has, and the `log` write filter fixture (`LogMirrorFilter`).

use std::sync::Arc;

use async_trait::async_trait;
use futures_util::FutureExt as _;
use serde_json::{Value as Json, json};
use utopia_database::Database;
use utopia_database::document::Document;
use utopia_database::mirror::{Failure, Filter, Mirror};
use utopia_database::query::Query;

use super::args::Args;
use super::calls::Calls;
#[allow(unused_imports)]
use super::convert::IntoObj as _;
use super::ctx::{Ctx, Db, R, fault, log};
use super::obj::Obj;
use super::{codec, enums, open};

/// The source and destination of a mirror `db.open` made, for `db.mirror`.
pub struct Parts {
    /// The source.
    pub source: Db,
    /// The destination, if any.
    pub destination: Option<Db>,
}

/// A mirror whose source is `adapter` and `cache` (`Core::mirror`): a
/// destination on PostgreSQL service `database-postgres-mirror`, its cache
/// `cache` (default `redis-mirror`, flushed), unless `"destination": false`;
/// `filters` names write filter fixtures.
pub async fn open(
    cx: &Arc<Ctx>,
    spec: &Args,
    adapter: Arc<dyn utopia_database::Adapter>,
    cache: Arc<utopia_cache::Cache>,
    sql: Option<Arc<utopia_database::adapter::sql::Sql>>,
) -> R<(Database, Parts)> {
    let source = Database::new(adapter, cache);
    let source_db = Db { database: source.clone(), sql, pool: None, mirror: None };
    let mut destination_db = None;
    if spec.bool_or("destination", true)? {
        let adapter = open::postgres(cx, "database-postgres-mirror").await?;
        let cache = open::cache(cx, &spec.string_or("cache", "redis-mirror")?, true).await?;
        let database = Database::new(adapter.clone(), cache);
        destination_db = Some(Db { database, sql: Some(adapter), pool: None, mirror: None });
    }
    let filters = match spec.raw("filters") {
        None | Some(Json::Null) => Vec::new(),
        Some(list) => codec::list(list)?
            .into_iter()
            .map(|name| mirror_filter(name.as_str().unwrap_or_default()))
            .collect::<R<Vec<_>>>()?,
    };
    let mirror = Mirror::new(source, destination_db.as_ref().map(|d| d.database.clone()), filters);
    Ok((mirror.into(), Parts { source: source_db, destination: destination_db }))
}

/// The operations only a mirror has (`database.*` and `object.call`), by
/// PHP method name; `None` for another method.
pub async fn invoke(db: &Database, method: &str, a: &Args) -> R<Option<Obj>> {
    let Some(mirror) = Mirror::of(db) else {
        return Ok(None);
    };
    let this = || Obj::object(super::database::DatabaseObj(db.clone()));
    Ok(Some(match method {
        "getSource" => Obj::object(super::database::DatabaseObj(mirror.source().clone())),
        "getDestination" => match mirror.destination() {
            Some(d) => Obj::object(super::database::DatabaseObj(d.clone())),
            None => Obj::null(),
        },
        "createUpgrades" => {
            mirror.create_upgrades().await?;
            Obj::null()
        }
        "awaitReplications" => {
            mirror.await_replications(a.opt_int("timeout")?)?;
            Obj::null()
        }
        "onError" => {
            let calls = Calls::new(&a.cx);
            let cb = calls.required("callback", a.raw("callback"))?;
            mirror.on_error(Arc::new(move |f: &Failure| {
                // A callback that only records its calls completes at once.
                match cb.invoke(vec![failure(f)]).now_or_never() {
                    Some(result) => result.map(|_| ()),
                    None => Err(utopia_database::Error::new(
                        utopia_database::Kind::Exception,
                        "compat fault: an onError callback with steps that wait is not supported",
                    )),
                }
            }));
            return Ok(Some(calls.finish(Ok(this()))?));
        }
        _ => return Ok(None),
    }))
}

/// `database.on_error`: a callback logging each failure as
/// `{"mirror": <method>, "callback": <name>, "event", "error"}`.
pub fn on_error(db: &Database, name: &str) -> R<Obj> {
    let Some(mirror) = Mirror::of(db) else {
        return fault("database.on_error: not a mirror");
    };
    let name = name.to_owned();
    mirror.on_error(Arc::new(move |f: &Failure| {
        log(json!({
            "mirror": f.method,
            "callback": name,
            "event": f.event.map(|e| e.as_str()),
            "error": codec::error(&f.error),
        }));
        Ok(())
    }));
    Ok(Obj::object(super::obj::Raw(json!({"$database": true}))))
}

/// `new Mirror($source, $destination, $filters)`.
pub fn construct(a: &Args) -> R<Obj> {
    let source = a.req::<super::database::DatabaseObj>("source")?.0;
    let destination = match a.raw("destination") {
        None | Some(Json::Null) => None,
        Some(_) => Some(a.req::<super::database::DatabaseObj>("destination")?.0),
    };
    let filters = match a.raw("filters") {
        None | Some(Json::Null) => Vec::new(),
        Some(list) => codec::list(list)?
            .into_iter()
            .map(|name| mirror_filter(name.as_str().unwrap_or_default()))
            .collect::<R<Vec<_>>>()?,
    };
    Ok(Obj::object(super::database::DatabaseObj(Mirror::new(source, destination, filters).into())))
}

/// A `Mirror\Failure` on the wire.
pub fn failure(failure: &Failure) -> Json {
    json!({"$value": "Mirror\\Failure", "fields": {
        "method": failure.method,
        "event": enums::encode_opt(failure.event),
        "error": codec::error(&failure.error),
    }})
}

/// A write filter fixture by name (`Core::mirrorFilter`).
pub fn mirror_filter(name: &str) -> R<Arc<dyn Filter>> {
    match name {
        "log" => Ok(Arc::new(LogMirrorFilter)),
        other => fault(format!("no mirror filter fixture `{other}`")),
    }
}

/// The write filter fixture `log` (`LogMirrorFilter`): logs each hook it
/// runs (`{"mirror_filter": <hook>, "collection", ...}`) and passes its
/// input through.
pub struct LogMirrorFilter;

fn hook(name: &str, collection: &str, extra: Json) {
    let mut entry = json!({"mirror_filter": name, "collection": collection});
    if let (Some(entry), Json::Object(extra)) = (entry.as_object_mut(), extra) {
        entry.extend(extra);
    }
    log(entry);
}

fn encoded(document: &Option<Document>) -> Json {
    document.as_ref().map_or(Json::Null, codec::encode_document)
}

type Out<T> = utopia_database::Result<T>;

#[async_trait]
impl Filter for LogMirrorFilter {
    async fn before_create_collection(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeCreateCollection", collection, json!({}));
        Ok(document)
    }

    async fn before_update_collection(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeUpdateCollection", collection, json!({}));
        Ok(document)
    }

    async fn before_delete_collection(&self, _: &Database, _: &Database, collection: &str) -> Out<()> {
        hook("beforeDeleteCollection", collection, json!({}));
        Ok(())
    }

    async fn before_create_attribute(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        attribute: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeCreateAttribute", collection, json!({"attribute": attribute, "document": encoded(&document)}));
        Ok(document)
    }

    async fn before_update_attribute(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        attribute: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeUpdateAttribute", collection, json!({"attribute": attribute}));
        Ok(document)
    }

    async fn before_delete_attribute(&self, _: &Database, _: &Database, collection: &str, attribute: &str) -> Out<()> {
        hook("beforeDeleteAttribute", collection, json!({"attribute": attribute}));
        Ok(())
    }

    async fn before_create_index(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        index: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeCreateIndex", collection, json!({"index": index, "document": encoded(&document)}));
        Ok(document)
    }

    async fn before_update_index(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        index: &str,
        document: Option<Document>,
    ) -> Out<Option<Document>> {
        hook("beforeUpdateIndex", collection, json!({"index": index}));
        Ok(document)
    }

    async fn before_delete_index(&self, _: &Database, _: &Database, collection: &str, index: &str) -> Out<()> {
        hook("beforeDeleteIndex", collection, json!({"index": index}));
        Ok(())
    }

    async fn before_create_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("beforeCreateDocument", collection, json!({"document": codec::encode_document(&document)}));
        Ok(document)
    }

    async fn after_create_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("afterCreateDocument", collection, json!({"id": document.id()}));
        Ok(document)
    }

    async fn before_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("beforeUpdateDocument", collection, json!({"document": codec::encode_document(&document)}));
        Ok(document)
    }

    async fn after_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("afterUpdateDocument", collection, json!({"id": document.id()}));
        Ok(document)
    }

    async fn before_update_documents(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        updates: Document,
        queries: &[Query],
    ) -> Out<Document> {
        hook(
            "beforeUpdateDocuments",
            collection,
            json!({"updates": codec::encode_document(&updates), "queries": queries.len()}),
        );
        Ok(updates)
    }

    async fn after_update_documents(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        _: &Document,
        queries: &[Query],
    ) -> Out<()> {
        hook("afterUpdateDocuments", collection, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn before_delete_document(&self, _: &Database, _: &Database, collection: &str, id: &str) -> Out<()> {
        hook("beforeDeleteDocument", collection, json!({"id": id}));
        Ok(())
    }

    async fn after_delete_document(&self, _: &Database, _: &Database, collection: &str, id: &str) -> Out<()> {
        hook("afterDeleteDocument", collection, json!({"id": id}));
        Ok(())
    }

    async fn before_delete_documents(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        queries: &[Query],
    ) -> Out<()> {
        hook("beforeDeleteDocuments", collection, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn after_delete_documents(&self, _: &Database, _: &Database, collection: &str, queries: &[Query]) -> Out<()> {
        hook("afterDeleteDocuments", collection, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn before_create_or_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("beforeCreateOrUpdateDocument", collection, json!({"document": codec::encode_document(&document)}));
        Ok(document)
    }

    async fn after_create_or_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection: &str,
        document: Document,
    ) -> Out<Document> {
        hook("afterCreateOrUpdateDocument", collection, json!({"id": document.id()}));
        Ok(document)
    }
}
