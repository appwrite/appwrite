//! PHP: `Documents/BulkWriteGuardsTest` (its Memory cases),
//! `Documents/OnNextTest`, `Documents/BatchSizeTest` and
//! `Documents/DocumentWriteMinorsTest` (on SQLite in PHP; the behaviour is
//! the database's, so here on Memory, except the cases that read SQL rows),
//! `Documents/TenantWriteGuardsTest` (SQLite in PHP, without the upsert
//! cases: Memory has no upserts), `Documents/StoredValueRevalidationTest`.

use std::sync::{Arc, Mutex};

use php_std::datetime::{DateTime, TzInfo};
use utopia_query::schema::ColumnType;

use super::{refused, s};
use crate::adapter::Adapter;
use crate::attribute::{Attribute, Format, Patch, Update};
use crate::database::Database;
use crate::database::filters::{json_decode_assoc, json_encode};
use crate::database::testing::block_on;
use crate::database::testing_memory::{POSTS, Tweaked, collection, database_on, doc, posts, seed};
use crate::document::Document;
use crate::error::{Error, Kind, Result};
use crate::filter::Callback;
use crate::permission::Permission;
use crate::query::Query;
use crate::role::Role;
use crate::validator::structure::formats;
use crate::value::{Array, ArrayKey, Str, Value, list};

fn int(i: i64) -> Value {
    Value::Int(i)
}

async fn value(database: &Database, collection: &str, id: &str, attribute: &str) -> Option<Value> {
    database.get_document(collection, id, Vec::new(), false).await.unwrap().get(attribute).cloned()
}

// ---- BulkWriteGuardsTest --------------------------------------------------

const TASKS: &str = "tasks";

fn task(id: &str, rank: i64) -> Document {
    let any = Role::any();
    doc(vec![
        ("$id", s(id)),
        ("$permissions", list([s(&Permission::read(&any)), s(&Permission::update(&any))])),
        ("rank", int(rank)),
        ("label", s("open")),
    ])
}

async fn tasks(permissions: Option<Vec<String>>) -> Database {
    let db = database_on(Arc::new(Tweaked::new()), "bulk").await;
    let any = Role::any();
    let permissions =
        permissions.unwrap_or_else(|| vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)]);
    collection(&db, TASKS, vec![Attribute::integer("rank"), Attribute::string("label", 16)], permissions, true).await;
    db
}

async fn corrupt_update_time(db: &Database, id: &str) {
    let definition = db.get_collection(TASKS).await.unwrap();
    let adapter = db.adapter().clone();
    let mut stored = adapter.get_document(&definition, id, Vec::new(), false).await.unwrap();
    stored.put("$updatedAt", s("not-a-date"));
    adapter.update_document(&definition, id, stored, true).await.unwrap();
}

#[test]
fn creating_documents_needs_create_permission() {
    block_on(async {
        let db = tasks(Some(vec![Permission::read(&Role::any())])).await;
        let err = db.create_documents(TASKS, vec![task("a", 1)], 1000, None).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Authorization);
        assert_eq!(db.count(TASKS, Vec::new(), None).await.unwrap(), 0);
    });
}

#[test]
fn an_update_without_changes_updates_nothing() {
    block_on(async {
        let db = tasks(None).await;
        db.create_documents(TASKS, vec![task("a", 1)], 1000, None).await.unwrap();
        assert_eq!(db.update_documents(TASKS, Document::empty(), Vec::new(), 1000, None).await.unwrap(), 0);
        assert_eq!(value(&db, TASKS, "a", "rank").await, Some(int(1)));
    });
}

#[test]
fn a_bulk_update_of_a_missing_collection_is_refused() {
    block_on(async {
        let db = tasks(None).await;
        let err =
            db.update_documents("missing", doc(vec![("rank", int(2))]), Vec::new(), 1000, None).await.unwrap_err();
        assert!(err.is_a(Kind::Database));
        assert_eq!(err.message(), "Collection not found");
    });
}

#[test]
fn a_bulk_update_with_an_invalid_query_or_a_foreign_cursor_is_refused() {
    block_on(async {
        let db = tasks(None).await;
        db.create_documents(TASKS, vec![task("a", 1)], 1000, None).await.unwrap();
        refused(
            db.update_documents(
                TASKS,
                doc(vec![("rank", int(2))]),
                vec![Query::equal("missing", vec!["x"])],
                1000,
                None,
            )
            .await,
            Kind::Query,
            "Invalid query: Attribute not found in schema: missing",
        );
        assert_eq!(value(&db, TASKS, "a", "rank").await, Some(int(1)));
        let foreign = doc(vec![("$id", s("a")), ("$collection", s("other"))]);
        refused(
            db.update_documents(TASKS, doc(vec![("rank", int(2))]), vec![Query::cursor_after(&foreign)], 1000, None)
                .await,
            Kind::Database,
            "Cursor document must be from the same Collection.",
        );
    });
}

#[test]
fn a_bulk_update_with_a_limit_above_the_batch_size_updates_exactly_the_limit() {
    block_on(async {
        let db = tasks(None).await;
        let all: Vec<Document> = (1..=7).map(|rank| task(&format!("t{rank}"), rank)).collect();
        db.create_documents(TASKS, all, 1000, None).await.unwrap();
        let queries = vec![Query::order_asc("rank", None), Query::limit(5)];
        assert_eq!(db.update_documents(TASKS, doc(vec![("label", s("done"))]), queries, 2, None).await.unwrap(), 5);
        let found = db.find(TASKS, vec![Query::order_asc("rank", None)], crate::permission::PermissionType::Read).await;
        let labels: Vec<(String, Option<Value>)> =
            found.unwrap().iter().map(|t| (t.id().to_owned(), t.get("label").cloned())).collect();
        let expected: Vec<(String, Option<Value>)> =
            (1..=7).map(|rank| (format!("t{rank}"), Some(s(if rank <= 5 { "done" } else { "open" })))).collect();
        assert_eq!(labels, expected);
    });
}

#[test]
fn a_bulk_update_over_an_unreadable_update_time_is_refused() {
    block_on(async {
        let db = tasks(None).await;
        db.create_documents(TASKS, vec![task("a", 1)], 1000, None).await.unwrap();
        corrupt_update_time(&db, "a").await;
        let updates = doc(vec![("rank", int(2)), ("$updatedAt", s("not-a-date"))]);
        let update =
            db.with_preserve_dates(true, Box::pin(db.update_documents(TASKS, updates, Vec::new(), 1000, None)));
        let err = Box::pin(db.skip_validation(update)).await.unwrap_err();
        assert!(err.is_a(Kind::Database), "{err:?}");
        assert_eq!(value(&db, TASKS, "a", "rank").await, Some(int(1)));
    });
}

// ---- OnNextTest -----------------------------------------------------------

#[test]
fn create_passes_no_previous_document() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let calls = Mutex::new(Vec::new());
        let mut on_next = |(document, previous): (Document, Option<Document>)| {
            calls.lock().unwrap().push((document.id().to_owned(), previous.is_none()));
            Ok(())
        };
        let third = doc(vec![("$id", s("third")), ("title", s("third")), ("views", int(3))]);
        db.create_documents(POSTS, vec![third], 1000, Some(&mut on_next)).await.unwrap();
        assert_eq!(*calls.lock().unwrap(), vec![("third".to_owned(), true)]);
    });
}

#[test]
fn update_passes_the_document_as_stored_before_the_update() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let calls = Mutex::new(Vec::new());
        let mut on_next = |(document, previous): (Document, Option<Document>)| {
            let title = |d: &Document| d.get("title").cloned();
            calls.lock().unwrap().push((title(&document), previous.as_ref().and_then(title)));
            Ok(())
        };
        let queries = vec![Query::equal("$id", vec!["first"])];
        db.update_documents(POSTS, doc(vec![("title", s("renamed"))]), queries, 1000, Some(&mut on_next))
            .await
            .unwrap();
        assert_eq!(*calls.lock().unwrap(), vec![(Some(s("renamed")), Some(s("first")))]);
    });
}

#[test]
fn delete_passes_the_stored_document() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let deleted = Mutex::new(Vec::new());
        let mut on_next = |document: Document| {
            deleted.lock().unwrap().push(document);
            Ok(())
        };
        let queries = vec![Query::equal("$id", vec!["second"])];
        assert_eq!(db.delete_documents(POSTS, queries, 1000, Some(&mut on_next)).await.unwrap(), 1);
        let deleted = deleted.lock().unwrap().clone();
        assert_eq!(deleted.len(), 1);
        assert_eq!(deleted[0].id(), "second");
        assert_eq!(deleted[0].get("views"), Some(&int(2)));
    });
}

#[test]
fn a_failing_callback_aborts_the_write() {
    block_on(async {
        let db = posts().await;
        let mut on_next = |_: (Document, Option<Document>)| Err(Error::new(Kind::Runtime, "stop"));
        let third = doc(vec![("$id", s("third")), ("title", s("third")), ("views", int(3))]);
        let err = db.create_documents(POSTS, vec![third], 1000, Some(&mut on_next)).await.unwrap_err();
        assert_eq!(err.message(), "stop");
    });
}

// ---- BatchSizeTest --------------------------------------------------------

async fn stored_views(db: &Database) -> Vec<(String, Option<Value>)> {
    let found = db.find(POSTS, Vec::new(), crate::permission::PermissionType::Read).await.unwrap();
    found.iter().map(|d| (d.id().to_owned(), d.get("views").cloned())).collect()
}

#[test]
fn a_batch_size_above_the_maximum_is_refused_before_any_write() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let message = "Batch size must be at most 1000, got 1001";
        let third = doc(vec![("$id", s("third")), ("title", s("third")), ("views", int(3))]);
        refused(db.create_documents(POSTS, vec![third], 1001, None).await, Kind::Limit, message);
        refused(
            db.update_documents(POSTS, doc(vec![("views", int(10))]), Vec::new(), 1001, None).await,
            Kind::Limit,
            message,
        );
        refused(db.delete_documents(POSTS, Vec::new(), 1001, None).await, Kind::Limit, message);
        let expected = vec![("first".to_owned(), Some(int(1))), ("second".to_owned(), Some(int(2)))];
        assert_eq!(stored_views(&db).await, expected);
    });
}

#[test]
fn the_maximum_batch_size_is_accepted() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let third = doc(vec![("$id", s("third")), ("title", s("third")), ("views", int(3))]);
        assert_eq!(db.create_documents(POSTS, vec![third], 1000, None).await.unwrap(), 1);
        assert!(db.update_documents(POSTS, doc(vec![("views", int(10))]), Vec::new(), 1000, None).await.unwrap() > 0);
        assert!(db.delete_documents(POSTS, Vec::new(), 1000, None).await.unwrap() > 0);
    });
}

#[test]
fn bulk_writes_work_through_batches_smaller_than_the_input() {
    block_on(async {
        let db = posts().await;
        seed(&db, &["first", "second"]).await;
        let created = Mutex::new(Vec::new());
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            created.lock().unwrap().push(document.id().to_owned());
            Ok(())
        };
        let more: Vec<Document> = (3..=7)
            .map(|views| doc(vec![("$id", s(&format!("more{views}"))), ("title", s("more")), ("views", int(views))]))
            .collect();
        assert_eq!(db.create_documents(POSTS, more, 2, Some(&mut on_next)).await.unwrap(), 5);
        assert_eq!(*created.lock().unwrap(), ["more3", "more4", "more5", "more6", "more7"]);
        assert_eq!(db.update_documents(POSTS, doc(vec![("title", s("same"))]), Vec::new(), 2, None).await.unwrap(), 7);
        assert_eq!(db.delete_documents(POSTS, Vec::new(), 2, None).await.unwrap(), 7);
        assert!(stored_views(&db).await.is_empty());
    });
}

// ---- DocumentWriteMinorsTest ----------------------------------------------

const MINORS: &str = "minors";

fn wrapped() -> Arc<dyn crate::filter::Codec> {
    Arc::new(Callback::new(
        "wrapped",
        |value| {
            if matches!(value, Value::Null) {
                return Ok(Value::Null);
            }
            let mut wrapper = Array::new();
            wrapper.set(ArrayKey::from("value"), value);
            Ok(json_encode(&Value::Array(wrapper)))
        },
        |value| {
            if matches!(value, Value::Null) {
                return Ok(Value::Null);
            }
            let decoded = match &value {
                Value::Str(text) => json_decode_assoc(text.as_bytes()),
                _ => Value::Null,
            };
            match decoded {
                Value::Array(a) if a.get_str("value").is_some() => {
                    Ok(a.get_str("value").cloned().unwrap_or(Value::Null))
                }
                _ => Err(Error::new(Kind::Runtime, format!("Decoded a value that was never encoded: {value:?}"))),
            }
        },
    ))
}

async fn minors(adapter: Arc<dyn Adapter>) -> Database {
    let cache = Arc::new(utopia_cache::Cache::from_dyn(Arc::new(utopia_cache::None)));
    let db = Database::with_filters(adapter, cache, vec![wrapped()]).unwrap();
    db.set_database("write_minors").set_namespace(&format!("write_minors_{}", crate::id::unique().unwrap()));
    db.create(None).await.unwrap();
    let any = Role::any();
    let attributes = vec![
        Attribute::string("name", 64),
        Attribute::integer("counter"),
        Attribute::string("secret", 1024).filters(["wrapped"]),
        Attribute::string("data", 1024).filters(["json"]),
        Attribute::datetime("seen"),
    ];
    collection(
        &db,
        MINORS,
        attributes,
        vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)],
        true,
    )
    .await;
    db
}

fn data(k: i64) -> Value {
    let mut a = Array::new();
    a.set(ArrayKey::from("k"), int(k));
    Value::Array(a)
}

#[test]
fn a_case_only_rename_answers_the_new_casing() {
    block_on(async {
        let db = minors(Arc::new(Tweaked::new().lower_case_reads())).await;
        db.create_document(MINORS, doc(vec![("$id", s("abc")), ("name", s("renamed"))])).await.unwrap();
        let renamed = db.update_document(MINORS, "ABC", doc(vec![("$id", s("ABC"))])).await.unwrap();
        assert_eq!(renamed.id(), "ABC");
    });
}

fn hour_ago() -> DateTime {
    DateTime::new(b"-1 hour", None, &TzInfo::utc()).unwrap()
}

#[test]
fn bulk_update_inside_a_request_timestamp_compares_the_stored_timestamp() {
    block_on(async {
        let db = minors(Arc::new(Tweaked::new())).await;
        db.create_document(MINORS, doc(vec![("$id", s("first")), ("counter", int(1))])).await.unwrap();
        let request = DateTime::new(b"now", None, &TzInfo::utc()).unwrap();
        std::thread::sleep(std::time::Duration::from_millis(5));
        let update = db.update_documents(MINORS, doc(vec![("counter", int(2))]), Vec::new(), 1000, None);
        assert_eq!(db.with_request_timestamp(Some(request), update).await.unwrap(), 1);
        assert_eq!(value(&db, MINORS, "first", "counter").await, Some(int(2)));
    });
}

#[test]
fn bulk_update_of_a_document_written_after_the_request_timestamp_conflicts() {
    block_on(async {
        let db = minors(Arc::new(Tweaked::new())).await;
        db.create_document(MINORS, doc(vec![("$id", s("first")), ("counter", int(1))])).await.unwrap();
        let update = db.update_documents(MINORS, doc(vec![("counter", int(2))]), Vec::new(), 1000, None);
        refused(
            db.with_request_timestamp(Some(hour_ago()), update).await,
            Kind::Conflict,
            "Document was updated after the request timestamp",
        );
        assert_eq!(value(&db, MINORS, "first", "counter").await, Some(int(1)));
    });
}

#[test]
fn bulk_update_hands_on_next_decoded_values_with_a_select() {
    block_on(async {
        let db = minors(Arc::new(Tweaked::new())).await;
        let first = doc(vec![("$id", s("first")), ("name", s("one")), ("counter", int(1)), ("data", data(1))]);
        db.create_document(MINORS, first).await.unwrap();
        let handed = Mutex::new(Vec::new());
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            handed.lock().unwrap().push(document);
            Ok(())
        };
        let queries = vec![Query::select(["counter"])];
        db.update_documents(MINORS, doc(vec![("data", data(2))]), queries, 1000, Some(&mut on_next)).await.unwrap();
        let handed = handed.lock().unwrap().clone();
        assert_eq!(handed.len(), 1);
        assert_eq!(handed[0].get("data"), Some(&data(2)));
        assert_eq!(handed[0].get("counter"), Some(&int(1)));
        assert!(!handed[0].has("name"));
        assert_eq!(value(&db, MINORS, "first", "data").await, Some(data(2)));
    });
}

#[test]
fn retried_bulk_update_hands_on_next_decoded_values() {
    block_on(async {
        let adapter = Arc::new(Tweaked::new());
        let db = minors(adapter.clone()).await;
        let first = doc(vec![("$id", s("first")), ("counter", int(1)), ("secret", s("alpha"))]);
        db.create_document(MINORS, first).await.unwrap();
        let handed = Mutex::new(Vec::new());
        let mut on_next = |(document, _): (Document, Option<Document>)| {
            handed.lock().unwrap().push(document);
            Ok(())
        };
        adapter.fail_commits(1);
        let modified =
            db.update_documents(MINORS, doc(vec![("counter", int(2))]), Vec::new(), 1000, Some(&mut on_next)).await;
        assert_eq!(adapter.commit_failures(), 0);
        assert_eq!(modified.unwrap(), 1);
        let handed = handed.lock().unwrap().clone();
        assert_eq!(handed.len(), 1);
        assert_eq!(handed[0].get("secret"), Some(&s("alpha")));
        assert_eq!(handed[0].get("counter"), Some(&int(2)));
        assert_eq!(value(&db, MINORS, "first", "secret").await, Some(s("alpha")));
        assert_eq!(value(&db, MINORS, "first", "counter").await, Some(int(2)));
    });
}

#[test]
fn bulk_update_hands_on_next_updated_values_decoded_once() {
    block_on(async {
        for queries in [Vec::new(), vec![Query::select(["counter"])]] {
            let db = minors(Arc::new(Tweaked::new())).await;
            let first = doc(vec![("$id", s("first")), ("counter", int(1)), ("secret", s("alpha"))]);
            db.create_document(MINORS, first).await.unwrap();
            let handed = Mutex::new(Vec::new());
            let mut on_next = |(document, _): (Document, Option<Document>)| {
                handed.lock().unwrap().push(document);
                Ok(())
            };
            db.update_documents(MINORS, doc(vec![("secret", s("gamma"))]), queries, 1000, Some(&mut on_next))
                .await
                .unwrap();
            let handed = handed.lock().unwrap().clone();
            assert_eq!(handed.len(), 1);
            assert_eq!(handed[0].get("secret"), Some(&s("gamma")));
            assert_eq!(handed[0].get("counter"), Some(&int(1)));
            assert_eq!(value(&db, MINORS, "first", "secret").await, Some(s("gamma")));
        }
    });
}

// ---- TenantWriteGuardsTest ------------------------------------------------

const NOTES: &str = "notes";

fn note(id: &str, tenant: Option<i64>) -> Document {
    let any = Role::any();
    doc(vec![
        ("$id", s(id)),
        ("$tenant", tenant.map_or(Value::Null, Value::Int)),
        ("$permissions", list([s(&Permission::read(&any)), s(&Permission::update(&any))])),
        ("body", s(id)),
    ])
}

async fn notes(shared: bool) -> Database {
    let db = database_on(Arc::new(Tweaked::new()), "tenants").await;
    db.set_shared_tables(shared).set_tenant(Value::Null);
    let any = Role::any();
    let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
    collection(&db, NOTES, vec![Attribute::string("body", 32)], permissions, true).await;
    db
}

#[test]
fn tenant_per_document_needs_shared_tables_for_every_write() {
    block_on(async {
        let db = notes(false).await;
        db.set_tenant_per_document(true);
        let message = "Shared tables must be enabled if tenant per document is enabled.";
        refused(db.create_document(NOTES, note("a", Some(1))).await, Kind::Database, message);
        refused(db.create_documents(NOTES, vec![note("a", Some(1))], 1000, None).await, Kind::Database, message);
        db.set_tenant_per_document(false);
        assert_eq!(db.count(NOTES, Vec::new(), None).await.unwrap(), 0);
    });
}

#[test]
fn a_tenant_per_document_write_needs_the_documents_tenant() {
    block_on(async {
        let db = notes(true).await;
        db.set_tenant_per_document(true);
        let message = "Missing tenant. Tenant must be set when tenant per document is enabled.";
        refused(db.create_document(NOTES, note("a", None)).await, Kind::Database, message);
        let documents = vec![note("b", Some(1)), note("c", None)];
        refused(db.create_documents(NOTES, documents, 1000, None).await, Kind::Database, message);
        assert_eq!(db.create_documents(NOTES, vec![note("e", Some(1))], 1000, None).await.unwrap(), 1);
        let read = db.with_tenant(int(1), db.get_document(NOTES, "e", Vec::new(), false)).await.unwrap();
        assert_eq!(read.tenant(), Some(int(1)));
    });
}

#[test]
fn bulk_writes_under_shared_tables_need_a_tenant() {
    block_on(async {
        let db = notes(true).await;
        let message = "Missing tenant. Tenant must be set when table sharing is enabled.";
        refused(db.create_documents(NOTES, vec![note("a", None)], 1000, None).await, Kind::Database, message);
        db.set_tenant(int(3));
        assert_eq!(db.create_documents(NOTES, vec![note("a", None)], 1000, None).await.unwrap(), 1);
        assert_eq!(db.get_document(NOTES, "a", Vec::new(), false).await.unwrap().tenant(), Some(int(3)));
    });
}

// ---- StoredValueRevalidationTest ------------------------------------------

fn options(attribute: &Array) -> Array {
    match attribute.get_str("formatOptions") {
        Some(Value::Array(a)) => a.clone(),
        _ => Array::new(),
    }
}

fn register_formats() {
    formats::add(
        "storedRange",
        Arc::new(|attribute: &Array| {
            let options = options(attribute);
            let bound = |key: &str| match options.get_str(key) {
                Some(Value::Int(i)) => *i,
                _ => 0,
            };
            Box::new(utopia_validators::Range::new(bound("min"), bound("max")))
        }),
        ColumnType::Integer,
    );
    formats::add(
        "storedEnum",
        Arc::new(|attribute: &Array| {
            let options = options(attribute);
            let elements: Vec<String> = match options.get_str("elements") {
                Some(Value::Array(a)) => {
                    a.values().filter_map(|v| crate::value::as_str(v).map(str::to_owned)).collect()
                }
                _ => Vec::new(),
            };
            let elements: Vec<&str> = elements.iter().map(String::as_str).collect();
            Box::new(utopia_validators::WhiteList::strict(&elements))
        }),
        ColumnType::String,
    );
}

fn format(name: &str, pairs: Vec<(&str, Value)>) -> Format {
    let options: Array = pairs.into_iter().map(|(k, v)| (ArrayKey::from(k), v)).collect();
    Format::new(name, options).unwrap()
}

fn range(min: i64, max: i64) -> Format {
    format("storedRange", vec![("min", int(min)), ("max", int(max))])
}

async fn rows() -> Database {
    register_formats();
    let db = database_on(Arc::new(Tweaked::new()), "stored").await;
    let any = Role::any();
    let attributes = vec![
        Attribute::integer("level").format(range(0, 100)),
        Attribute::string("kind", 8).format(format("storedEnum", vec![("elements", list([s("a"), s("b")]))])),
        Attribute::string("note", 32),
    ];
    let permissions = vec![Permission::create(&any), Permission::read(&any), Permission::update(&any)];
    collection(&db, "rows", attributes, permissions, false).await;
    let row = doc(vec![("$id", s("row")), ("level", int(50)), ("kind", s("b")), ("note", s("before"))]);
    db.create_document("rows", row).await.unwrap();
    db
}

fn narrowing(format: Format) -> Update {
    Update { format: Patch::Set(Some(format)), ..Update::default() }
}

#[test]
fn an_update_keeps_a_stored_value_a_narrowed_range_no_longer_admits() {
    block_on(async {
        let db = rows().await;
        db.update_attribute("rows", "level", narrowing(range(0, 10))).await.unwrap();
        let updated = db.update_document("rows", "row", doc(vec![("note", s("after narrow"))])).await.unwrap();
        assert_eq!(updated.get("note"), Some(&s("after narrow")));
        assert_eq!(value(&db, "rows", "row", "note").await, Some(s("after narrow")));
        assert_eq!(value(&db, "rows", "row", "level").await, Some(int(50)));
    });
}

#[test]
fn an_update_keeps_a_stored_value_a_shrunk_enum_no_longer_admits() {
    block_on(async {
        let db = rows().await;
        let shrunk = format("storedEnum", vec![("elements", list([s("a")]))]);
        db.update_attribute("rows", "kind", narrowing(shrunk)).await.unwrap();
        db.update_document("rows", "row", doc(vec![("note", s("after shrink"))])).await.unwrap();
        assert_eq!(value(&db, "rows", "row", "note").await, Some(s("after shrink")));
        assert_eq!(value(&db, "rows", "row", "kind").await, Some(s("b")));
    });
}

#[test]
fn an_update_that_writes_a_value_the_narrowed_range_refuses_fails() {
    block_on(async {
        let db = rows().await;
        db.update_attribute("rows", "level", narrowing(range(0, 10))).await.unwrap();
        refused(
            db.update_document("rows", "row", doc(vec![("level", int(60)), ("note", s("written"))])).await,
            Kind::Structure,
            "Invalid document structure: Attribute \"level\" has invalid format. Value must be a valid range between 0 and 10",
        );
        assert_eq!(value(&db, "rows", "row", "note").await, Some(s("before")));
    });
}

#[allow(dead_code)]
fn unused(_: Str, _: Result<()>) {}
