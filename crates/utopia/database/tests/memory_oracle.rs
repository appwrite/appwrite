//! The Memory adapter against PHP: `fixtures/memory_scenario.json` is a
//! list of adapter calls; `fixtures/memory_expected.txt` is what
//! utopia-php/database 8.1.3's `Adapter\Memory` returned for each
//! (`serialize()`d, base64-encoded: documents as `getArrayCopy()`,
//! exceptions as their class and message), followed by the adapter's
//! properties (`$databases`, `$data`, `$permissions`,
//! `$permissionsByDocument`, `$permissionsByPermission`,
//! `$uniqueIndexHashes`), serialized the same way. Every line must match
//! byte for byte.
//!
//! The PHP side is `fixtures/memory_run.php` (`php memory_run.php memory_scenario.json`): it reads the
//! same scenario with `Attribute::fromDocument`, `Index::fromDocument`,
//! `Relationship::fromDocument`, `Query::parse` and `Operator::parse`.

#![cfg(feature = "memory")]

use std::sync::Arc;

use serde_json::Value as Json;
use utopia_database::adapter::memory::Memory;
use utopia_database::adapter::{Adapter, Find, Relationships};
use utopia_database::attribute::Attribute;
use utopia_database::collection::Collection;
use utopia_database::operator::Operator;
use utopia_database::permission::PermissionType;
use utopia_database::relationship::{Relationship, RelationshipSide, Update};
use utopia_database::validator::authorization::Authorization;
use utopia_database::value::{Array, ArrayKey, Str, Value};
use utopia_database::{Document, Error, Index, Query, Result};
use utopia_query::{CursorDirection, OrderDirection};

/// A JSON value as PHP's `json_decode($json, true)` gives it, with every
/// `{"$op": ...}` an `Operator::parse()`d operator.
fn value(json: &Json) -> Value {
    match json {
        Json::Object(map) if map.contains_key("$op") => {
            Value::from(Operator::parse(serde_json::to_string(&map["$op"]).unwrap()).unwrap())
        }
        Json::Object(map) => Value::Array(
            map.iter().map(|(k, v)| (ArrayKey::normalize(Str::from(k.as_str())), value(v))).collect(),
        ),
        Json::Array(items) => Value::Array(Array::from_list(items.iter().map(value).collect())),
        other => utopia_database::value::from_plain(php_std::Value::from_json(other)),
    }
}

fn doc(json: &Json) -> Result<Document> {
    match value(json) {
        Value::Array(a) => Document::new(a),
        _ => Ok(Document::empty()),
    }
}

fn docs(json: &Json) -> Result<Vec<Document>> {
    json.as_array().unwrap().iter().map(doc).collect()
}

fn queries(json: Option<&Json>) -> Result<Vec<Query>> {
    match json {
        Some(Json::Array(items)) => items.iter().map(|q| Query::parse(&serde_json::to_string(q).unwrap(), false)).collect(),
        _ => Ok(Vec::new()),
    }
}

fn collection(json: &Json) -> Collection {
    let mut document = Array::new();
    document.set(ArrayKey::from("$id"), Value::Str(Str::from(json.as_str().unwrap())));
    Collection::from_document(&Document::new(document).unwrap()).unwrap()
}

fn text(json: &Json) -> &str {
    json.as_str().unwrap()
}

fn export(documents: Vec<Document>) -> Value {
    Value::Array(Array::from_list(documents.into_iter().map(|d| Value::Array(d.array_copy())).collect()))
}

fn error(e: Error) -> Value {
    let mut a = Array::new();
    a.set(ArrayKey::from("error"), Value::Str(Str::from(e.php_class())));
    a.set(ArrayKey::from("message"), Value::Str(Str::from(e.message_bytes().to_vec())));
    Value::Array(a)
}

fn relationship(json: &Json) -> Relationship {
    Relationship::from_document(&doc(json).unwrap()).unwrap()
}

async fn run(adapter: &Memory, authorization: &Arc<Authorization>, op: &str, a: &Json) -> Result<Value> {
    let arg = |i: usize| &a[i];
    let bool_ = |b: Result<bool>| b.map(Value::Bool);
    Ok(match op {
        "database" => {
            adapter.set_database(text(a));
            Value::Bool(false)
        }
        "namespace" => {
            adapter.set_namespace(text(a));
            Value::Bool(false)
        }
        "shared" => {
            adapter.set_shared_tables(a.as_bool().unwrap());
            Value::Bool(false)
        }
        "tenant" => {
            adapter.set_tenant(value(a));
            Value::Bool(false)
        }
        "perDocument" => {
            adapter.set_tenant_per_document(a.as_bool().unwrap());
            Value::Bool(false)
        }
        "roles" => {
            authorization.clean_roles();
            for role in a.as_array().unwrap() {
                authorization.add_role(text(role));
            }
            Value::Null
        }
        "authorization" => {
            if a.as_bool().unwrap() {
                authorization.enable();
            } else {
                authorization.disable();
            }
            Value::Null
        }
        "ignore" => export(
            adapter
                .state()
                .ignoring_duplicates()
                .with(true, adapter.create_documents(&collection(arg(0)), docs(arg(1))?))
                .await?,
        ),
        "create" => bool_(adapter.create(text(a)).await)?,
        "update" => bool_(adapter.update(text(arg(0)), text(arg(1))).await)?,
        "exists" => bool_(adapter.exists(text(a)).await)?,
        "collectionExists" => bool_(adapter.collection_exists(text(arg(0)), text(arg(1))).await)?,
        "list" => export(adapter.list().await?),
        "delete" => bool_(adapter.delete(text(a)).await)?,
        "createCollection" => {
            let attributes: Vec<Attribute> = match a.get(1) {
                Some(Json::Array(items)) => items.iter().map(|x| Attribute::from_document(&doc(x)?)).collect::<Result<_>>()?,
                _ => Vec::new(),
            };
            let indexes: Vec<Index> = match a.get(2) {
                Some(Json::Array(items)) => items.iter().map(|x| Index::from_document(&doc(x)?)).collect::<Result<_>>()?,
                _ => Vec::new(),
            };
            bool_(adapter.create_collection(text(arg(0)), &attributes, &indexes).await)?
        }
        "deleteCollection" => bool_(adapter.delete_collection(text(a)).await)?,
        "createAttribute" => bool_(adapter.create_attribute(text(arg(0)), &Attribute::from_document(&doc(arg(1))?)?).await)?,
        "updateAttribute" => {
            bool_(adapter.update_attribute(text(arg(0)), text(arg(1)), &Attribute::from_document(&doc(arg(2))?)?).await)?
        }
        "deleteAttribute" => bool_(adapter.delete_attribute(text(arg(0)), text(arg(1))).await)?,
        "renameAttribute" => bool_(adapter.rename_attribute(text(arg(0)), text(arg(1)), text(arg(2))).await)?,
        "createIndex" => bool_(adapter.create_index(text(arg(0)), &Index::from_document(&doc(arg(1))?)?, &[], &[]).await)?,
        "deleteIndex" => bool_(adapter.delete_index(text(arg(0)), text(arg(1))).await)?,
        "renameIndex" => bool_(adapter.rename_index(text(arg(0)), text(arg(1)), text(arg(2))).await)?,
        "createRelationship" => bool_(adapter.create_relationship(text(arg(0)), &relationship(arg(1))).await)?,
        "updateRelationship" => {
            let update = Update {
                key: arg(3).get("key").and_then(Json::as_str).map(str::to_owned),
                two_way_key: arg(3).get("twoWayKey").and_then(Json::as_str).map(str::to_owned),
                two_way: arg(3).get("twoWay").and_then(Json::as_bool),
                on_delete: None,
            };
            let side = RelationshipSide::from_value(text(arg(2))).unwrap();
            bool_(adapter.update_relationship(text(arg(0)), &relationship(arg(1)), side, &update).await)?
        }
        "deleteRelationship" => {
            let side = RelationshipSide::from_value(text(arg(2))).unwrap();
            bool_(adapter.delete_relationship(text(arg(0)), &relationship(arg(1)), side).await)?
        }
        "createDocument" => Value::Array(adapter.create_document(&collection(arg(0)), doc(arg(1))?).await?.array_copy()),
        "createDocuments" => export(adapter.create_documents(&collection(arg(0)), docs(arg(1))?).await?),
        "getDocument" => Value::Array(
            adapter.get_document(&collection(arg(0)), text(arg(1)), queries(a.get(2))?, false).await?.array_copy(),
        ),
        "updateDocument" => {
            let skip = a.get(3).and_then(Json::as_bool).unwrap_or(false);
            Value::Array(adapter.update_document(&collection(arg(0)), text(arg(1)), doc(arg(2))?, skip).await?.array_copy())
        }
        "updateDocuments" => {
            Value::Int(adapter.update_documents(&collection(arg(0)), &doc(arg(1))?, docs(arg(2))?, &[]).await?)
        }
        "getSequences" => export(adapter.get_sequences(&collection(arg(0)), docs(arg(1))?).await?),
        "deleteDocument" => bool_(adapter.delete_document(&collection(arg(0)), text(arg(1))).await)?,
        "deleteDocuments" => {
            let sequences = arg(1).as_array().unwrap().iter().map(value).collect();
            let ids = arg(2).as_array().unwrap().iter().map(|v| text(v).to_owned()).collect();
            Value::Int(adapter.delete_documents(&collection(arg(0)), sequences, ids).await?)
        }
        "increase" => {
            let bound = |i: usize| a.get(i).filter(|v| !v.is_null()).map(value);
            bool_(
                adapter
                    .increase_document_attribute(
                        &collection(arg(0)),
                        text(arg(1)),
                        text(arg(2)),
                        value(arg(3)),
                        text(arg(4)),
                        bound(5),
                        bound(6),
                    )
                    .await,
            )?
        }
        "find" => {
            let options = a.get(2).cloned().unwrap_or(Json::Null);
            let mut find = Find::default();
            if let Some(limit) = options.get("limit") {
                find.limit = limit.as_i64();
            }
            find.offset = options.get("offset").and_then(Json::as_i64);
            if let Some(Json::Array(items)) = options.get("orderAttributes") {
                find.order_attributes = items.iter().map(|v| text(v).to_owned()).collect();
            }
            if let Some(Json::Array(items)) = options.get("orderTypes") {
                find.order_types = items.iter().map(|v| OrderDirection::from_value(text(v)).unwrap()).collect();
            }
            if let Some(cursor) = options.get("cursor")
                && let Value::Array(cursor) = value(cursor)
            {
                find.cursor = cursor;
            }
            if let Some(direction) = options.get("direction") {
                find.cursor_direction = CursorDirection::from_value(text(direction)).unwrap();
            }
            if let Some(permission) = options.get("permission") {
                find.for_permission = PermissionType::from_name(text(permission)).unwrap();
            }
            export(adapter.find(&collection(arg(0)), queries(a.get(1))?, find).await?)
        }
        "count" => Value::Int(adapter.count(&collection(arg(0)), queries(a.get(1))?, a.get(2).and_then(Json::as_i64)).await?),
        "sum" => adapter.sum(&collection(arg(0)), text(arg(1)), queries(a.get(2))?, a.get(3).and_then(Json::as_i64)).await?,
        "size" => Value::Int(adapter.size_of_collection(text(a)).await?),
        "start" => bool_(adapter.start_transaction().await)?,
        "commit" => bool_(adapter.commit_transaction().await)?,
        "rollback" => bool_(adapter.rollback_transaction().await)?,
        "apply" => adapter.apply_operator(&value(arg(0)), &Operator::parse(serde_json::to_string(arg(1)).unwrap())?)?,
        other => panic!("unknown op {other}"),
    })
}

#[tokio::test]
async fn test_memory_matches_php() {
    check(include_str!("fixtures/memory_scenario.json"), include_str!("fixtures/memory_expected.txt")).await;
}

/// Uniqueness across updates and renames, permissions moved by renames and
/// batch updates, tenant per document, big-integer increments, ordering and
/// cursors over mixed types.
#[tokio::test]
async fn test_memory_matches_php_on_writes_permissions_and_tenants() {
    check(include_str!("fixtures/memory_scenario2.json"), include_str!("fixtures/memory_expected2.txt")).await;
}

async fn check(scenario: &str, expected: &str) {
    let scenario: Json = serde_json::from_str(scenario).unwrap();
    let expected: Vec<&str> = expected.lines().collect();
    let adapter = Memory::new();
    let authorization = Arc::new(Authorization::new(true));
    adapter.set_authorization(authorization.clone());

    let mut lines = Vec::new();
    for (i, op) in scenario.as_array().unwrap().iter().enumerate() {
        let name = text(&op[0]);
        let result = run(&adapter, &authorization, name, op.get(1).unwrap_or(&Json::Null)).await.unwrap_or_else(error);
        lines.push((format!("{i} {name}"), php_std::serialize::serialize(&result)));
    }
    for (name, array) in adapter.properties() {
        lines.push((name.to_owned(), php_std::serialize::serialize(&Value::Array(array))));
    }

    let mut failures = Vec::new();
    for ((label, actual), expected) in lines.iter().zip(&expected) {
        let (_, encoded) = expected.split_once(' ').unwrap();
        let expected = php_std::encoding::base64_decode(encoded.as_bytes(), true).unwrap();
        if *actual != expected {
            failures.push(format!(
                "{label}\n  php:  {}\n  rust: {}",
                String::from_utf8_lossy(&expected),
                String::from_utf8_lossy(actual)
            ));
        }
    }
    assert_eq!(lines.len(), expected.len(), "one line per op and property");
    assert!(failures.is_empty(), "{} of {} differ:\n{}", failures.len(), lines.len(), failures.join("\n"));
}
