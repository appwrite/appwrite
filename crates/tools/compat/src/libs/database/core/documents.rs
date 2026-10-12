//! Area `documents` of `core`: document CRUD, finds, counts, sums, bulk
//! writes, upserts, increments, operators, permissions, the query cache and
//! custom document types (`database.*` operations on `Database`'s
//! `documents.rs`, `find.rs` and the document-type part of `config.rs`).
//!
//! Owner: the documents agent. See [`super::args`] for the shared API.

use serde_json::Value as Json;
use utopia_database::value::Value;
use utopia_database::{Database, Error};

use super::args::{self, Args, Calls, R, database_marker, document, documents, encode, fault};
use crate::adapter::Session;

/// Operations of this area.
pub const OPS: &[&str] = &[
    "database.count",
    "database.create_document",
    "database.create_documents",
    "database.increase_document_attribute",
    "database.decrease_document_attribute",
    "database.delete_document",
    "database.delete_documents",
    "database.find",
    "database.find_one",
    "database.foreach",
    "database.get_document",
    "database.iterate",
    "database.sum",
    "database.update_document",
    "database.update_documents",
    "database.upsert_document",
    "database.upsert_documents",
    "database.upsert_documents_with_increase",
    "database.convert_query",
    "database.convert_queries",
    "database.get_query_cache_field",
    "database.get_query_cache_key",
    "database.purge_cached_document",
    "database.purge_cached_queries",
    "database.skip_duplicates",
    "database.set_document_type",
    "database.get_document_type",
    "database.clear_document_type",
    "database.clear_all_document_types",
];

/// The `Document` subclasses the PHP driver can load (the e2e suite's
/// custom document types), for `setDocumentType`'s class checks.
const DOCUMENT_CLASSES: &[&str] =
    &["Utopia\\Database\\Document", "Tests\\E2E\\Adapter\\Scopes\\TestUser", "Tests\\E2E\\Adapter\\Scopes\\TestPost"];

/// Other classes that exist in the PHP driver and that tests name.
const OTHER_CLASSES: &[&str] = &["stdClass", "ArrayObject", "Exception", "Utopia\\Database\\Database"];

/// `true` for an `int` / `float` argument, `default` when absent.
fn number_or(a: &Args<'_>, key: &str, default: Value) -> R<Value> {
    Ok(a.opt_value(key)?.filter(|v| !matches!(v, Value::Null)).unwrap_or(default))
}

/// Runs one operation of this area.
pub async fn call(op: &str, json: &Json, session: &mut Session) -> R<Json> {
    let a = Args::new(json);
    let db = args::db(json, session)?;
    match op {
        "database.get_document" => {
            let queries = a.queries("queries")?;
            let doc = db
                .get_document(&a.string("collection")?, &a.string("id")?, queries, a.bool_or("forUpdate", false)?)
                .await?;
            Ok(document(&doc))
        }
        "database.create_document" => {
            let doc = db.create_document(&a.string("collection")?, a.document("document")?).await?;
            Ok(document(&doc))
        }
        "database.create_documents" => {
            let (collection, list) = (a.string("collection")?, a.documents("documents")?);
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = db
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
        _ => more(op, &a, &db, session).await,
    }
}

/// The operations after the first few (split for readability).
async fn more(op: &str, a: &Args<'_>, db: &Database, session: &mut Session) -> R<Json> {
    match op {
        "database.update_document" => {
            let doc = db.update_document(&a.string("collection")?, &a.string("id")?, a.document("document")?).await?;
            Ok(document(&doc))
        }
        "database.update_documents" => {
            let collection = a.string("collection")?;
            let updates = a.document("updates")?;
            let queries = a.queries("queries")?;
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = db
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
            let doc = db.upsert_document(&a.string("collection")?, a.document("document")?).await?;
            Ok(document(&doc))
        }
        "database.upsert_documents" | "database.upsert_documents_with_increase" => {
            let collection = a.string("collection")?;
            let attribute = a.string_or("attribute", "")?;
            let list = a.documents("documents")?;
            let batch_size = a.int_or("batchSize", Database::INSERT_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = db
                .upsert_documents_with_increase(
                    &collection,
                    &attribute,
                    list,
                    next.as_mut().map(|c| c as _),
                    error.as_mut().map(|c| c as _),
                    batch_size,
                )
                .await;
            calls.finish(r.map(Json::from).map_err(Into::into))
        }
        "database.increase_document_attribute" | "database.decrease_document_attribute" => {
            let (collection, id, attribute) = (a.string("collection")?, a.string("id")?, a.string("attribute")?);
            let value = number_or(a, "value", Value::Int(1))?;
            let doc = if op == "database.increase_document_attribute" {
                let max = a.opt_value("max")?.filter(|v| !matches!(v, Value::Null));
                db.increase_document_attribute(&collection, &id, &attribute, value, max).await?
            } else {
                let min = a.opt_value("min")?.filter(|v| !matches!(v, Value::Null));
                db.decrease_document_attribute(&collection, &id, &attribute, value, min).await?
            };
            Ok(document(&doc))
        }
        "database.delete_document" => {
            Ok(Json::Bool(db.delete_document(&a.string("collection")?, &a.string("id")?).await?))
        }
        "database.delete_documents" => {
            let collection = a.string("collection")?;
            let queries = a.queries("queries")?;
            let batch_size = a.int_or("batchSize", Database::DELETE_BATCH_SIZE)?;
            let calls = Calls::new(session);
            let on_next = calls.callback("onNext", a.raw("onNext"))?;
            let on_error = calls.callback("onError", a.raw("onError"))?;
            let (mut next, mut error) = (on_next.clone(), on_error.clone());
            let r = db
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
        "database.find" => {
            let permission = a.string_or("forPermission", Database::PERMISSION_READ)?;
            let found = db.find(&a.string("collection")?, a.queries("queries")?, &permission).await?;
            Ok(documents(&found))
        }
        "database.find_one" => {
            let found = db.find_one(&a.string("collection")?, a.queries("queries")?).await?;
            Ok(document(&found))
        }
        "database.iterate" => {
            use futures_util::StreamExt;
            let permission = a.string_or("forPermission", Database::PERMISSION_READ)?;
            let mut stream = db.iterate(&a.string("collection")?, a.queries("queries")?, &permission);
            let mut found = Vec::new();
            while let Some(doc) = stream.next().await {
                found.push(doc?);
            }
            Ok(documents(&found))
        }
        "database.foreach" => {
            let collection = a.string("collection")?;
            let queries = a.queries("queries")?;
            let permission = a.string_or("forPermission", Database::PERMISSION_READ)?;
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = db.foreach(&collection, &mut cb.clone(), queries, &permission).await;
            calls.finish(r.map(|()| Json::Null).map_err(Into::into))
        }
        "database.count" => {
            let count = db.count(&a.string("collection")?, a.queries("queries")?, a.opt_int("max")?).await?;
            Ok(Json::from(count))
        }
        "database.sum" => {
            let sum = db
                .sum(&a.string("collection")?, &a.string("attribute")?, a.queries("queries")?, a.opt_int("max")?)
                .await?;
            Ok(encode(&sum))
        }
        "database.convert_queries" => {
            let collection = a.document("collection")?;
            let queries = db.convert_queries(&collection, a.queries("queries")?)?;
            Ok(encode(&Value::Array(queries.into_iter().map(Value::from).collect())))
        }
        "database.convert_query" => {
            let collection = a.document("collection")?;
            let query = db.convert_query(&collection, a.query("query")?)?;
            Ok(encode(&Value::from(query)))
        }
        "database.get_query_cache_field" => {
            let collection = a.opt_document("collection")?;
            let field = db.query_cache_field(
                collection.as_ref(),
                &a.queries("queries")?,
                &a.string_or("field", "documents")?,
                &a.string_or("forPermission", Database::PERMISSION_READ)?,
            )?;
            Ok(field.map(Json::String).unwrap_or(Json::Null))
        }
        "database.get_query_cache_key" => {
            let namespace = a.opt_string("namespace")?;
            Ok(Json::String(db.query_cache_key(&a.string("collectionId")?, namespace.as_deref())))
        }
        "database.purge_cached_document" => {
            let id = a.opt_string("id")?;
            Ok(Json::Bool(db.purge_cached_document(&a.string("collectionId")?, id.as_deref()).await?))
        }
        "database.purge_cached_queries" => {
            let namespace = a.opt_string("namespace")?;
            Ok(Json::Bool(db.purge_cached_queries(&a.string("collection")?, namespace.as_deref()).await?))
        }
        "database.skip_duplicates" => {
            let calls = Calls::new(session);
            let Some(cb) = calls.callback("callback", a.raw("callback"))? else {
                return fault("missing `callback`");
            };
            let r = db.skip_duplicates(|| cb.invoke(Vec::new())).await;
            calls.finish(r.map(|v| encode(&v.unwrap_or(Value::Null))).map_err(Into::into))
        }
        "database.set_document_type" => {
            let (collection, class) = (a.string("collection")?, a.string("className")?);
            if !DOCUMENT_CLASSES.contains(&class.as_str()) {
                let message = if OTHER_CLASSES.contains(&class.as_str()) {
                    format!("Class {class} must extend Utopia\\Database\\Document")
                } else {
                    format!("Class {class} does not exist")
                };
                return Err(Error::database(message).into());
            }
            db.set_document_type(&collection, &class)?;
            Ok(database_marker())
        }
        "database.get_document_type" => {
            Ok(db.document_type(&a.string("collection")?).map(Json::String).unwrap_or(Json::Null))
        }
        "database.clear_document_type" => {
            db.clear_document_type(&a.string("collection")?);
            Ok(database_marker())
        }
        "database.clear_all_document_types" => {
            db.clear_all_document_types();
            Ok(database_marker())
        }
        _ => fault(format!("database: unknown documents operation `{op}`")),
    }
}
