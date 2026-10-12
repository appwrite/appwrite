//! The operations a `Utopia\\Database\\Mirror` overrides, on a database
//! `db.open` made with `mirror`: they go to [`Mirror`] (PHP dispatches them
//! to the subclass); every other operation runs on the mirror's own
//! database ([`Mirror::database`]). Also the mirror's own methods
//! (`database.get_source`, `get_destination`, `get_write_filters`,
//! `on_error`, `create_upgrades`) and `db.mirror`.

use std::sync::Arc;

use async_trait::async_trait;
use serde_json::{Value as Json, json};
use utopia_database::document::Document;
use utopia_database::mirror::Mirror;
use utopia_database::mirroring::Filter;
use utopia_database::query::Query;
use utopia_database::value::Value;
use utopia_database::{Database, Error};

use super::args::{self, Args, Calls, R, database_marker, document, encode, fault, log};
use super::documents::{check_document_class, number_or};
use super::schema::{arrays, list};
use crate::adapter::Session;

/// The operations [`call`] takes for a mirror.
pub const OPS: &[&str] = &[
    "database.set_database",
    "database.set_namespace",
    "database.set_shared_tables",
    "database.set_tenant",
    "database.set_drop_unknown_attributes",
    "database.set_preserve_dates",
    "database.set_preserve_sequence",
    "database.enable_validation",
    "database.disable_validation",
    "database.skip_validation",
    "database.on",
    "database.silent",
    "database.with_request_timestamp",
    "database.exists",
    "database.create",
    "database.delete",
    "database.create_collection",
    "database.update_collection",
    "database.delete_collection",
    "database.create_attribute",
    "database.create_attributes",
    "database.update_attribute",
    "database.delete_attribute",
    "database.update_attribute_required",
    "database.update_attribute_format",
    "database.update_attribute_format_options",
    "database.update_attribute_filters",
    "database.update_attribute_default",
    "database.rename_attribute",
    "database.create_relationship",
    "database.update_relationship",
    "database.delete_relationship",
    "database.create_index",
    "database.delete_index",
    "database.rename_index",
    "database.create_document",
    "database.create_documents",
    "database.update_document",
    "database.update_documents",
    "database.upsert_document",
    "database.upsert_documents",
    "database.delete_document",
    "database.delete_documents",
    "database.increase_document_attribute",
    "database.decrease_document_attribute",
    "database.set_authorization",
    "database.set_document_type",
    "database.clear_document_type",
    "database.clear_all_document_types",
    "database.get_source",
    "database.get_destination",
    "database.get_write_filters",
    "database.on_error",
    "database.create_upgrades",
];

/// The mirror's own operations, which no plain database has.
pub const OWN: &[&str] = &[
    "database.get_source",
    "database.get_destination",
    "database.get_write_filters",
    "database.on_error",
    "database.create_upgrades",
    "db.mirror",
];

/// Runs an operation of a mirror.
pub async fn call(op: &str, json: &Json, session: &mut Session, mirror: &Mirror) -> R<Json> {
    let a = Args::new(json);
    let marker = |r: utopia_database::Result<&Mirror>| -> R<Json> { r.map(|_| database_marker()).map_err(Into::into) };
    match op {
        "database.set_database" => marker(mirror.set_database(&a.string("name")?).await),
        "database.set_namespace" => marker(mirror.set_namespace(&a.string("namespace")?).await),
        "database.set_shared_tables" => marker(mirror.set_shared_tables(a.bool("sharedTables")?).await),
        "database.set_tenant" => marker(mirror.set_tenant(a.value("tenant")?).await),
        "database.set_drop_unknown_attributes" => marker(mirror.set_drop_unknown_attributes(a.bool("drop")?).await),
        "database.set_preserve_dates" => marker(mirror.set_preserve_dates(a.bool("preserve")?).await),
        "database.set_preserve_sequence" => marker(mirror.set_preserve_sequence(a.bool("preserve")?).await),
        "database.enable_validation" => marker(mirror.enable_validation().await),
        "database.disable_validation" => marker(mirror.disable_validation().await),
        "database.skip_validation" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = match mirror.skip_validation(|| cb.invoke(Vec::new())).await {
                Ok(r) => r,
                Err(e) => Err(e),
            };
            calls.finish(r.map(|v| encode(&v.unwrap_or(Value::Null))).map_err(Into::into))
        }
        "database.on" => {
            let (event, name) = (a.string_or("event", "")?, a.string_or("name", "")?);
            mirror.on(&event, &name, args::listener(&name, a.raw("callback")));
            Ok(database_marker())
        }
        "database.silent" => {
            let listeners = a.opt_strings("listeners")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = mirror.silent(|| cb.invoke(Vec::new()), listeners.as_deref()).await;
            calls.finish(r.map(|v| encode(&v.unwrap_or(Value::Null))).map_err(Into::into))
        }
        "database.with_request_timestamp" => {
            let timestamp = a.datetime("requestTimestamp")?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = mirror.with_request_timestamp(timestamp, || cb.invoke(Vec::new())).await;
            calls.finish(r.map(|v| encode(&v.unwrap_or(Value::Null))).map_err(Into::into))
        }
        "database.exists" => Ok(Json::Bool(
            mirror.exists(a.opt_string("database")?.as_deref(), a.opt_string("collection")?.as_deref()).await?,
        )),
        "database.create" => Ok(Json::Bool(mirror.create(a.opt_string("database")?.as_deref()).await?)),
        "database.delete" => Ok(Json::Bool(mirror.delete(a.opt_string("database")?.as_deref()).await?)),
        "database.create_collection" => Ok(document(
            &mirror
                .create_collection(
                    &a.string("id")?,
                    a.documents("attributes")?,
                    a.documents("indexes")?,
                    a.opt_strings("permissions")?,
                    a.bool_or("documentSecurity", true)?,
                )
                .await?,
        )),
        "database.update_collection" => Ok(document(
            &mirror.update_collection(&a.string("id")?, a.strings("permissions")?, a.bool("documentSecurity")?).await?,
        )),
        "database.delete_collection" => Ok(Json::Bool(mirror.delete_collection(&a.string("id")?).await?)),
        "database.create_attribute" => Ok(Json::Bool(
            mirror
                .create_attribute(
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
            Ok(Json::Bool(mirror.create_attributes(&a.string("collection")?, arrays(&a, "attributes")?).await?))
        }
        "database.update_attribute" => {
            let format_options = if a.given("formatOptions") { Some(a.array("formatOptions")?) } else { None };
            Ok(document(
                &mirror
                    .update_attribute(
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
        "database.delete_attribute" => {
            Ok(Json::Bool(mirror.delete_attribute(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.update_attribute_required" => Ok(document(
            &mirror.update_attribute_required(&a.string("collection")?, &a.string("id")?, a.bool("required")?).await?,
        )),
        "database.update_attribute_format" => Ok(document(
            &mirror.update_attribute_format(&a.string("collection")?, &a.string("id")?, &a.string("format")?).await?,
        )),
        "database.update_attribute_format_options" => Ok(document(
            &mirror
                .update_attribute_format_options(&a.string("collection")?, &a.string("id")?, a.array("formatOptions")?)
                .await?,
        )),
        "database.update_attribute_filters" => Ok(document(
            &mirror.update_attribute_filters(&a.string("collection")?, &a.string("id")?, a.strings("filters")?).await?,
        )),
        "database.update_attribute_default" => Ok(document(
            &mirror.update_attribute_default(&a.string("collection")?, &a.string("id")?, a.value("default")?).await?,
        )),
        "database.rename_attribute" => Ok(Json::Bool(
            mirror.rename_attribute(&a.string("collection")?, &a.string("old")?, &a.string("new")?).await?,
        )),
        "database.create_relationship" => {
            let id = a.opt_string("id")?;
            let two_way_key = a.opt_string("twoWayKey")?;
            let on_delete = a.string_or("onDelete", Database::RELATION_MUTATE_RESTRICT)?;
            Ok(Json::Bool(
                mirror
                    .create_relationship(
                        &a.string("collection")?,
                        &a.string("relatedCollection")?,
                        &a.string("type")?,
                        a.bool_or("twoWay", false)?,
                        id.as_deref(),
                        two_way_key.as_deref(),
                        &on_delete,
                    )
                    .await?,
            ))
        }
        "database.update_relationship" => {
            let new_key = a.opt_string("newKey")?;
            let new_two_way_key = a.opt_string("newTwoWayKey")?;
            let on_delete = a.opt_string("onDelete")?;
            Ok(Json::Bool(
                mirror
                    .update_relationship(
                        &a.string("collection")?,
                        &a.string("id")?,
                        new_key.as_deref(),
                        new_two_way_key.as_deref(),
                        a.opt_bool("twoWay")?,
                        on_delete.as_deref(),
                    )
                    .await?,
            ))
        }
        "database.delete_relationship" => {
            Ok(Json::Bool(mirror.delete_relationship(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.create_index" => Ok(Json::Bool(
            mirror
                .create_index(
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
        "database.delete_index" => {
            Ok(Json::Bool(mirror.delete_index(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.rename_index" => {
            Ok(Json::Bool(mirror.rename_index(&a.string("collection")?, &a.string("old")?, &a.string("new")?).await?))
        }
        _ => documents(op, &a, session, mirror).await,
    }
}

/// The document operations of a mirror (split for readability).
async fn documents(op: &str, a: &Args<'_>, session: &mut Session, mirror: &Mirror) -> R<Json> {
    match op {
        "database.create_document" => {
            Ok(document(&mirror.create_document(&a.string("collection")?, a.document("document")?).await?))
        }
        "database.create_documents" => {
            let (collection, list) = (a.string("collection")?, a.documents("documents")?);
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = mirror
                .create_documents(
                    &collection,
                    list,
                    batch_size,
                    next.as_mut().map(|c| c as _),
                    error.as_mut().map(|c| c as _),
                )
                .await;
            calls.finish(r.map(Json::from).map_err(Into::into))
        }
        "database.update_document" => Ok(document(
            &mirror.update_document(&a.string("collection")?, &a.string("id")?, a.document("document")?).await?,
        )),
        "database.update_documents" => {
            let collection = a.string("collection")?;
            let updates = a.document("updates")?;
            let queries: Vec<Query> = a.queries("queries")?;
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = mirror
                .update_documents(
                    &collection,
                    updates,
                    queries,
                    batch_size,
                    next.as_mut().map(|c| c as _),
                    error.as_mut().map(|c| c as _),
                )
                .await;
            calls.finish(r.map(Json::from).map_err(Into::into))
        }
        "database.upsert_document" => {
            Ok(document(&mirror.upsert_document(&a.string("collection")?, a.document("document")?).await?))
        }
        "database.upsert_documents" => {
            let collection = a.string("collection")?;
            let list = a.documents("documents")?;
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = mirror
                .upsert_documents(
                    &collection,
                    list,
                    batch_size,
                    next.as_mut().map(|c| c as _),
                    error.as_mut().map(|c| c as _),
                )
                .await;
            calls.finish(r.map(Json::from).map_err(Into::into))
        }
        "database.delete_document" => {
            Ok(Json::Bool(mirror.delete_document(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.delete_documents" => {
            let collection = a.string("collection")?;
            let queries = a.queries("queries")?;
            let batch_size = a.int_or("batchSize", Database::DELETE_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = mirror
                .delete_documents(
                    &collection,
                    queries,
                    batch_size,
                    next.as_mut().map(|c| c as _),
                    error.as_mut().map(|c| c as _),
                )
                .await;
            calls.finish(r.map(Json::from).map_err(Into::into))
        }
        "database.increase_document_attribute" | "database.decrease_document_attribute" => {
            let (collection, id, attribute) = (a.string("collection")?, a.string("id")?, a.string("attribute")?);
            let value = number_or(a, "value", Value::Int(1))?;
            let doc = if op == "database.increase_document_attribute" {
                let max = a.opt_value("max")?.filter(|v| !matches!(v, Value::Null));
                mirror.increase_document_attribute(&collection, &id, &attribute, value, max).await?
            } else {
                let min = a.opt_value("min")?.filter(|v| !matches!(v, Value::Null));
                mirror.decrease_document_attribute(&collection, &id, &attribute, value, min).await?
            };
            Ok(document(&doc))
        }
        "database.set_authorization" => {
            mirror.set_authorization(a.authorization("authorization", session)?);
            Ok(database_marker())
        }
        "database.set_document_type" => {
            let (collection, class) = (a.string("collection")?, a.string("className")?);
            check_document_class(&class)?;
            mirror.set_document_type(&collection, &class).await?;
            Ok(database_marker())
        }
        "database.clear_document_type" => {
            mirror.clear_document_type(&a.string("collection")?).await?;
            Ok(database_marker())
        }
        "database.clear_all_document_types" => {
            mirror.clear_all_document_types().await?;
            Ok(database_marker())
        }
        "database.get_source" | "database.get_destination" => {
            if op == "database.get_destination" && mirror.destination().is_none() {
                return Ok(Json::Null);
            }
            Ok(database_marker())
        }
        "database.get_write_filters" => {
            Ok(Json::Array(mirror.write_filters().iter().map(|_| json!({"$filter": true})).collect()))
        }
        "database.on_error" => {
            let name = a.string_or("name", "error")?;
            mirror.on_error(Arc::new(move |action: &str, error: &Error| {
                log(json!({"mirror": action, "callback": name, "error": args::error(error)}));
                Ok(())
            }));
            Ok(Json::Null)
        }
        "database.create_upgrades" => {
            mirror.create_upgrades().await?;
            Ok(Json::Null)
        }
        _ => fault(format!("database: unknown mirror operation `{op}`")),
    }
}

/// `db.mirror`: a handle on a mirror's `source` or `destination` (`part`),
/// as its `getSource()` / `getDestination()` return them (`null` without a
/// destination).
pub fn handle(a: &Args<'_>, session: &mut Session, mirror: &args::MirrorHandle) -> R<Json> {
    let part = match a.string_or("part", "source")?.as_str() {
        "source" => mirror.source.clone(),
        "destination" => match &mirror.destination {
            Some(d) => d.clone(),
            None => return Ok(Json::Null),
        },
        other => return fault(format!("db.mirror: no part `{other}`")),
    };
    Ok(session.handle(part))
}

/// A Mirroring filter fixture by name (`Core::mirroringFilter`): `log`
/// logs every hook it runs (`{"mirroring": <hook>, "collection", ...}`) and
/// passes its input through.
pub fn filter(name: &str) -> R<Arc<dyn Filter>> {
    match name {
        "log" => Ok(Arc::new(LogFilter)),
        _ => fault(format!("no mirroring filter fixture `{name}`")),
    }
}

struct LogFilter;

fn hook(name: &str, collection: &str, extra: Json) {
    let mut entry = json!({"mirroring": name, "collection": collection});
    if let (Json::Object(e), Json::Object(x)) = (&mut entry, extra) {
        e.extend(x);
    }
    log(entry);
}

fn doc(d: &Option<Document>) -> Json {
    d.as_ref().map(document).unwrap_or(Json::Null)
}

#[async_trait]
impl Filter for LogFilter {
    async fn before_create_collection(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> utopia_database::Result<Option<Document>> {
        hook("beforeCreateCollection", collection_id, json!({}));
        Ok(collection)
    }

    async fn before_update_collection(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        collection: Option<Document>,
    ) -> utopia_database::Result<Option<Document>> {
        hook("beforeUpdateCollection", collection_id, json!({}));
        Ok(collection)
    }

    async fn before_delete_collection(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
    ) -> utopia_database::Result<()> {
        hook("beforeDeleteCollection", collection_id, json!({}));
        Ok(())
    }

    async fn before_create_attribute(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        attribute_id: &str,
        attribute: Option<Document>,
    ) -> utopia_database::Result<Option<Document>> {
        hook("beforeCreateAttribute", collection_id, json!({"attribute": attribute_id, "document": doc(&attribute)}));
        Ok(attribute)
    }

    async fn before_update_attribute(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        attribute_id: &str,
        attribute: Option<Document>,
    ) -> utopia_database::Result<Option<Document>> {
        hook("beforeUpdateAttribute", collection_id, json!({"attribute": attribute_id}));
        Ok(attribute)
    }

    async fn before_delete_attribute(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        attribute_id: &str,
    ) -> utopia_database::Result<()> {
        hook("beforeDeleteAttribute", collection_id, json!({"attribute": attribute_id}));
        Ok(())
    }

    async fn before_create_index(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        index_id: &str,
        index: Option<Document>,
    ) -> utopia_database::Result<Option<Document>> {
        hook("beforeCreateIndex", collection_id, json!({"index": index_id, "document": doc(&index)}));
        Ok(index)
    }

    async fn before_delete_index(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        index_id: &str,
    ) -> utopia_database::Result<()> {
        hook("beforeDeleteIndex", collection_id, json!({"index": index_id}));
        Ok(())
    }

    async fn before_create_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("beforeCreateDocument", collection_id, json!({"document": document(&document_)}));
        Ok(document_)
    }

    async fn after_create_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("afterCreateDocument", collection_id, json!({"id": document_.id()}));
        Ok(document_)
    }

    async fn before_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("beforeUpdateDocument", collection_id, json!({"document": document(&document_)}));
        Ok(document_)
    }

    async fn after_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("afterUpdateDocument", collection_id, json!({"id": document_.id()}));
        Ok(document_)
    }

    async fn before_update_documents(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        updates: Document,
        queries: &[Query],
    ) -> utopia_database::Result<Document> {
        hook("beforeUpdateDocuments", collection_id, json!({"updates": document(&updates), "queries": queries.len()}));
        Ok(updates)
    }

    async fn after_update_documents(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        _updates: Document,
        queries: &[Query],
    ) -> utopia_database::Result<()> {
        hook("afterUpdateDocuments", collection_id, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn before_delete_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_id: &str,
    ) -> utopia_database::Result<()> {
        hook("beforeDeleteDocument", collection_id, json!({"id": document_id}));
        Ok(())
    }

    async fn after_delete_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_id: &str,
    ) -> utopia_database::Result<()> {
        hook("afterDeleteDocument", collection_id, json!({"id": document_id}));
        Ok(())
    }

    async fn before_delete_documents(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        queries: &[Query],
    ) -> utopia_database::Result<()> {
        hook("beforeDeleteDocuments", collection_id, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn after_delete_documents(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        queries: &[Query],
    ) -> utopia_database::Result<()> {
        hook("afterDeleteDocuments", collection_id, json!({"queries": queries.len()}));
        Ok(())
    }

    async fn before_create_or_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("beforeCreateOrUpdateDocument", collection_id, json!({"document": document(&document_)}));
        Ok(document_)
    }

    async fn after_create_or_update_document(
        &self,
        _: &Database,
        _: &Database,
        collection_id: &str,
        document_: Document,
    ) -> utopia_database::Result<Document> {
        hook("afterCreateOrUpdateDocument", collection_id, json!({"id": document_.id()}));
        Ok(document_)
    }
}
