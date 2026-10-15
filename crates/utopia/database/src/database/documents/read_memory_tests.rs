//! The reads' tests on the Memory adapter.
//!
//! PHP: `tests/unit/Event/FindOneMissTest.php`, `Documents/CursorTest.php`,
//! `Documents/GetDocumentFallbackTest.php`, `SelectProjectionTest.php`,
//! `SelectFilterSkipTest.php`, `ForUpdateCacheTest.php`,
//! `LenientReadTest.php` (Memory), `Documents/BatchSizeTest.php` (cursor).
//! PHP runs some of `CursorTest` on SQLite; they run on Memory here.

use std::sync::{Arc, Mutex};

use async_trait::async_trait;
use futures_util::StreamExt;
use utopia_query::Method;

use super::super::Hook;
use super::super::fixtures::{
    POSTS, Recorder, any, created, definition, doc, hooks_memory, memory_cache, no_cache, seed,
};
use super::super::testing::block_on;
use crate::adapter::Adapter;
use crate::adapter::memory::Memory;
use crate::attribute::Attribute;
use crate::collection::Collection;
use crate::database::Database;
use crate::document::Document;
use crate::error::{Kind, Result};
use crate::event::{Domain, Event};
use crate::hook::Queries;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::value::{Value, string};

fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

async fn cursor_ids(db: &Database, queries: Vec<Query>, batch: i64, permission: PermissionType) -> Vec<String> {
    let documents: Vec<Document> =
        db.cursor(POSTS, queries, batch, permission).await.unwrap().map(|d| d.unwrap()).collect().await;
    ids(&documents)
}

async fn seeded(count: usize) -> Database {
    let db = hooks_memory().await;
    let names: Vec<String> = (1..=count).map(|i| format!("doc{i}")).collect();
    let names: Vec<&str> = names.iter().map(String::as_str).collect();
    seed(&db, &names).await;
    db
}

// ---- Event/FindOneMissTest -------------------------------------------------

#[test]
fn find_one_fires_a_find_event_only_on_a_hit() {
    block_on(async {
        let db = hooks_memory().await;
        seed(&db, &["first"]).await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        let found = db.find_one(POSTS, vec![Query::equal("$id", ["missing"])]).await.unwrap();
        assert!(found.is_empty());
        assert!(recorder.received(Event::DocumentFind).is_empty());

        let db = hooks_memory().await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        assert!(db.find_one(POSTS, Vec::new()).await.unwrap().is_empty());
        assert!(recorder.received(Event::DocumentFind).is_empty());

        let db = hooks_memory().await;
        seed(&db, &["first", "second"]).await;
        let recorder = Recorder::new();
        db.add_hook(Hook::lifecycle(recorder.clone())).unwrap();
        let found = db.find_one(POSTS, vec![Query::equal("$id", ["second"])]).await.unwrap();
        assert_eq!(found.id(), "second");
        let events = recorder.received(Event::DocumentFind);
        assert_eq!(events.len(), 1);
        let Domain::DocumentFound { collection, documents } = &events[0] else { panic!("a find event") };
        assert_eq!(collection, POSTS);
        assert_eq!(documents, &vec![found]);
    });
}

// ---- Documents/CursorTest --------------------------------------------------

/// Records the limit of every read of `posts` (PHP overrides `find()`).
#[derive(Default)]
struct Limits(Mutex<Vec<Option<i64>>>);

#[async_trait]
impl Queries for Limits {
    async fn rewrite(&self, _event: Event, collection: &Collection, queries: Vec<Query>) -> Result<Vec<Query>> {
        if collection.id() == POSTS {
            for query in &queries {
                if query.method() == Method::Limit {
                    let limit = match query.value() {
                        Some(utopia_query::Value::Int(i)) => Some(*i),
                        _ => None,
                    };
                    self.0.lock().unwrap().push(limit);
                }
            }
        }
        Ok(queries)
    }
}

#[test]
fn the_default_page_is_the_cursor_batch_size() {
    block_on(async {
        let db = hooks_memory().await;
        let names: Vec<String> = (1..=150).map(|i| format!("doc{i}")).collect();
        let names: Vec<&str> = names.iter().map(String::as_str).collect();
        seed(&db, &names).await;
        let limits = Arc::new(Limits::default());
        db.add_hook(Hook::queries(limits.clone())).unwrap();
        let all = cursor_ids(&db, Vec::new(), super::super::CURSOR_BATCH_SIZE, PermissionType::Read).await;
        assert_eq!(all.len(), 150);
        assert_eq!(*limits.0.lock().unwrap(), [Some(100), Some(100)]);
    });
}

#[test]
fn a_limit_caps_the_documents_yielded_and_an_offset_or_cursor_positions_the_first_page() {
    block_on(async {
        let db = seeded(7).await;
        let views = || Query::order_asc("views", None);
        assert_eq!(cursor_ids(&db, vec![views(), Query::limit(3)], 2, PermissionType::Read).await, ["doc1", "doc2", "doc3"]);

        let db = seeded(5).await;
        let second = db.get_document(POSTS, "doc2", Vec::new(), false).await.unwrap();
        assert_eq!(cursor_ids(&db, vec![views(), Query::offset(2)], 2, PermissionType::Read).await, ["doc3", "doc4", "doc5"]);
        assert_eq!(
            cursor_ids(&db, vec![views(), Query::cursor_after(&second)], 2, PermissionType::Read).await,
            ["doc3", "doc4", "doc5"]
        );
    });
}

#[test]
fn a_cursor_before_or_an_array_cursor_is_refused() {
    block_on(async {
        let db = seeded(2).await;
        let first = db.get_document(POSTS, "doc1", Vec::new(), false).await.unwrap();
        let err = db.cursor(POSTS, vec![Query::cursor_before(&first)], 100, PermissionType::Read).await.err().unwrap();
        assert_eq!((err.kind(), err.message()), (Kind::Database, "Cursor before not supported in this method."));

        let array = || {
            let mut values = utopia_query::Array::new();
            values.set(php_std::ArrayKey::from("$id"), utopia_query::string("doc1"));
            Query::cursor_after_value(utopia_query::Value::Array(values))
        };
        let err = db.skip_validation(db.cursor(POSTS, vec![array()], 100, PermissionType::Read)).await.err().unwrap();
        assert_eq!(err.kind(), Kind::Query);
        assert_eq!(err.message(), "Invalid query: Invalid cursor: a cursor must be a document, array given");
        let err = db.skip_validation(db.find(POSTS, vec![array()], PermissionType::Read)).await.unwrap_err();
        assert_eq!(err.message(), "Invalid query: Invalid cursor: a cursor must be a document, array given");
    });
}

#[test]
fn the_permission_the_cursor_reads_under() {
    block_on(async {
        let db = hooks_memory().await;
        db.add_hook(Hook::write(Arc::new(crate::hook::permissions::Permissions::new()))).unwrap();
        db.create_collection(definition("notes", vec![Attribute::string("title", 64)], any(&["create"]), true))
            .await
            .unwrap();
        let role = Role::any();
        for (id, permissions) in [
            ("editable", vec![Permission::read(&role), Permission::update(&role)]),
            ("readable", vec![Permission::read(&role)]),
        ] {
            let permissions = permissions.into_iter().map(string).collect::<Vec<_>>();
            db.create_document(
                "notes",
                doc(vec![("$id", string(id)), ("$permissions", crate::value::list(permissions)), ("title", string(id))]),
            )
            .await
            .unwrap();
        }
        let read = |permission| {
            let db = db.clone();
            async move {
                let documents: Vec<Document> = db
                    .cursor("notes", vec![Query::order_asc("title", None)], 100, permission)
                    .await
                    .unwrap()
                    .map(|d| d.unwrap())
                    .collect()
                    .await;
                ids(&documents)
            }
        };
        assert_eq!(read(PermissionType::Read).await, ["editable", "readable"]);
        assert_eq!(read(PermissionType::Update).await, ["editable"]);
    });
}

// ---- Documents/BatchSizeTest (cursor) -------------------------------------

#[test]
fn a_cursor_batch_above_the_maximum_is_refused_and_the_maximum_accepted() {
    block_on(async {
        let db = seeded(2).await;
        let err = db.cursor(POSTS, Vec::new(), super::super::BATCH_SIZE + 1, PermissionType::Read).await.err().unwrap();
        assert_eq!(err.kind(), Kind::Limit);
        assert_eq!(cursor_ids(&db, Vec::new(), super::super::BATCH_SIZE, PermissionType::Read).await.len(), 2);
    });
}

// ---- Documents/GetDocumentFallbackTest ------------------------------------

async fn sessions(adapter: Arc<dyn Adapter>, cache: Arc<utopia_cache::Cache>) -> Database {
    let db = created(adapter, cache, "fallback", "fallback", false).await;
    db.create_collection(definition(
        "sessions",
        vec![Attribute::string("owner", 32), Attribute::datetime("startedAt")],
        any(&["create", "read"]),
        false,
    ))
    .await
    .unwrap();
    db
}

#[test]
fn get_document_refuses_an_empty_collection_id_and_a_join_without_joins() {
    block_on(async {
        let db = sessions(Arc::new(Memory::new()), memory_cache()).await;
        let err = db.get_document("", "session", Vec::new(), false).await.unwrap_err();
        assert_eq!((err.kind(), err.message()), (Kind::NotFound, "Collection not found"));

        let join = Query::join("sessions", "twin", vec![Query::on("owner", "owner", "=")]).unwrap();
        let err = db.get_document("sessions", "session", vec![join], false).await.unwrap_err();
        assert_eq!(err.kind(), Kind::Query);
        assert!(err.message().contains("Invalid query method: join"), "{}", err.message());
    });
}

// ---- SelectProjectionTest --------------------------------------------------

async fn widgets() -> Database {
    let db = created(Arc::new(Memory::new()), memory_cache(), "utopiaTests", "select", false).await;
    db.create_collection(Collection::create("widgets", "widgets", Vec::new(), Vec::new(), None, true, Default::default()).unwrap())
        .await
        .unwrap();
    db.create_attribute("widgets", Attribute::string("sku", super::super::LENGTH_KEY)).await.unwrap();
    db.create_document(
        "widgets",
        doc(vec![
            ("$id", string("widget")),
            ("$permissions", crate::value::list([string(Permission::read(&Role::any()))])),
            ("sku", string("abc")),
        ]),
    )
    .await
    .unwrap();
    db
}

#[test]
fn a_malformed_selection_is_refused_as_a_query_error() {
    block_on(async {
        let db = widgets().await;
        let list = |items: Vec<utopia_query::Value>| utopia_query::Value::Array(utopia_query::list(items));
        let s = utopia_query::string;
        for values in [
            vec![list(vec![s("sku")])],
            vec![list(vec![s("*")])],
            vec![s("sku"), list(vec![s("x")])],
            vec![list(vec![s("a")]), list(vec![s("b")])],
        ] {
            let select = Query::new(Method::Select, "", utopia_query::list(values), "");
            let err = db.find("widgets", vec![select], PermissionType::Read).await.unwrap_err();
            assert_eq!(err.kind(), Kind::Query);
            assert!(err.message().contains("Attribute selection must be a string, got"), "{}", err.message());
        }

        let rows = db.find("widgets", vec![Query::select(["sku"])], PermissionType::Read).await.unwrap();
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0].get("sku"), Some(&string("abc")));
        assert_eq!(db.find("widgets", vec![Query::select(["*"])], PermissionType::Read).await.unwrap().len(), 1);

        let err = db.find("widgets", vec![Query::select(["nope"])], PermissionType::Read).await.unwrap_err();
        assert!(err.message().contains("Attribute not found in schema: nope"), "{}", err.message());
    });
}

// ---- SelectFilterSkipTest --------------------------------------------------

/// `subQueryProbeUnit`: decodes to `['fanned', 'out']`, counting its calls.
struct Probe(Arc<Mutex<usize>>);

#[async_trait]
impl super::super::Filter for Probe {
    async fn encode(&self, _value: Value, _document: &Document, _database: &Database) -> Result<Value> {
        Ok(Value::Null)
    }

    async fn decode(&self, _value: Value, _document: &Document, _database: &Database, _attribute: &str) -> Result<Value> {
        *self.0.lock().unwrap() += 1;
        Ok(crate::value::list([string("fanned"), string("out")]))
    }

    fn signature(&self) -> String {
        "probe:probe".to_owned()
    }
}

#[test]
fn a_filter_is_not_applied_when_its_attribute_is_not_selected() {
    block_on(async {
        let calls = Arc::new(Mutex::new(0usize));
        Database::add_filter("subQueryProbeUnit", Arc::new(Probe(calls.clone())));
        let db = created(Arc::new(Memory::new()), memory_cache(), "utopiaTests", "select_filter", false).await;
        db.create_collection(
            Collection::create("filterSelect", "filterSelect", Vec::new(), Vec::new(), None, true, Default::default())
                .unwrap(),
        )
        .await
        .unwrap();
        db.create_attribute("filterSelect", Attribute::string("plain", 128)).await.unwrap();
        let mut kids = Attribute::string("kids", 128);
        kids.filters = vec!["subQueryProbeUnit".to_owned()];
        db.create_attribute("filterSelect", kids).await.unwrap();
        let role = Role::any();
        db.create_document(
            "filterSelect",
            doc(vec![
                ("$id", string("doc1")),
                (
                    "$permissions",
                    crate::value::list(
                        [Permission::read(&role), Permission::update(&role), Permission::delete(&role)].map(string),
                    ),
                ),
                ("plain", string("x")),
            ]),
        )
        .await
        .unwrap();
        let fanned = crate::value::list([string("fanned"), string("out")]);
        let reset = || *calls.lock().unwrap() = 0;
        let count = || *calls.lock().unwrap();

        reset();
        let document = db.get_document("filterSelect", "doc1", Vec::new(), false).await.unwrap();
        assert_eq!((count(), document.get("kids")), (1, Some(&fanned)));

        reset();
        let document =
            db.get_document("filterSelect", "doc1", vec![Query::select(["$id", "plain"])], false).await.unwrap();
        assert_eq!((count(), document.get("kids"), document.get("plain")), (0, None, Some(&string("x"))));

        reset();
        let document =
            db.get_document("filterSelect", "doc1", vec![Query::select(["$id", "kids"])], false).await.unwrap();
        assert_eq!((count(), document.get("kids")), (1, Some(&fanned)));

        reset();
        let document = db.get_document("filterSelect", "doc1", vec![Query::select(["*"])], false).await.unwrap();
        assert_eq!((count(), document.get("kids")), (1, Some(&fanned)));

        reset();
        let documents =
            db.find("filterSelect", vec![Query::select(["$id", "plain"])], PermissionType::Read).await.unwrap();
        assert_eq!((documents.len(), count(), documents[0].get("kids")), (1, 0, None));

        reset();
        let documents = db.find("filterSelect", Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!((documents.len(), count(), documents[0].get("kids")), (1, 1, Some(&fanned)));
    });
}

// ---- ForUpdateCacheTest ----------------------------------------------------

async fn projects() -> (Arc<Memory>, Database) {
    let adapter = Arc::new(Memory::new());
    let db = created(adapter.clone(), memory_cache(), "utopiaTests", "for_update", false).await;
    db.create_collection(
        Collection::create("projects", "projects", Vec::new(), Vec::new(), None, true, Default::default()).unwrap(),
    )
    .await
    .unwrap();
    db.create_attribute("projects", Attribute::string("name", super::super::LENGTH_KEY)).await.unwrap();
    db.create_attribute("projects", Attribute::string("description", super::super::LENGTH_KEY)).await.unwrap();
    let role = Role::any();
    db.create_document(
        "projects",
        doc(vec![
            ("$id", string("project")),
            ("$permissions", crate::value::list([Permission::read(&role), Permission::update(&role)].map(string))),
            ("name", string("stale")),
            ("description", string("stale")),
        ]),
    )
    .await
    .unwrap();
    (adapter, db)
}

/// Writes the row through the adapter, past the cache purge.
async fn stale_cache(adapter: &Memory, db: &Database, attribute: &str, value: &str) {
    let collection = db.get_collection("projects").await.unwrap();
    let mut document = adapter.get_document(&collection, "project", Vec::new(), false).await.unwrap();
    document.put(attribute, string(value));
    adapter.update_document(&collection, "project", document, true).await.unwrap();
}

async fn name(db: &Database, for_update: bool) -> Option<Value> {
    db.get_document("projects", "project", Vec::new(), for_update).await.unwrap().get("name").cloned()
}

#[test]
fn a_purge_makes_the_next_read_fresh() {
    block_on(async {
        let (adapter, db) = projects().await;
        assert_eq!(name(&db, false).await, Some(string("stale")));
        stale_cache(&adapter, &db, "name", "fresh").await;
        assert_eq!(name(&db, false).await, Some(string("stale")));
        db.purge_cached_document("projects", "project").await.unwrap();
        assert_eq!(name(&db, false).await, Some(string("fresh")));
    });
}

#[test]
fn a_locking_read_bypasses_the_stale_cache_without_filling_it() {
    block_on(async {
        let (adapter, db) = projects().await;
        assert_eq!(name(&db, false).await, Some(string("stale")));
        stale_cache(&adapter, &db, "name", "fresh").await;
        assert_eq!(name(&db, false).await, Some(string("stale")));
        assert_eq!(name(&db, true).await, Some(string("fresh")));
        assert_eq!(name(&db, false).await, Some(string("stale")), "the locking read leaves the cache untouched");
    });
}

#[test]
fn an_update_does_not_resurrect_stale_cached_attributes() {
    block_on(async {
        let (adapter, db) = projects().await;
        name(&db, false).await;
        stale_cache(&adapter, &db, "name", "fresh").await;
        db.update_document("projects", "project", doc(vec![("description", string("updated"))])).await.unwrap();

        let collection = db.get_collection("projects").await.unwrap();
        let row = adapter.get_document(&collection, "project", Vec::new(), false).await.unwrap();
        assert_eq!(row.get("name"), Some(&string("fresh")));
        assert_eq!(row.get("description"), Some(&string("updated")));
        let document = db.get_document("projects", "project", Vec::new(), false).await.unwrap();
        assert_eq!(document.get("name"), Some(&string("fresh")));
        assert_eq!(document.get("description"), Some(&string("updated")));
    });
}

// ---- LenientReadTest (Memory) ---------------------------------------------

async fn lenient() -> Database {
    let adapter = Arc::new(Memory::new());
    let db = Database::new(adapter.clone(), no_cache());
    let authorization = Arc::new(crate::validator::authorization::Authorization::new(true));
    authorization.add_role("any");
    db.set_authorization(authorization);
    db.set_database("lenient").set_namespace("lenient");
    db.create(None).await.unwrap();
    db.create_collection(definition(
        "notes",
        vec![Attribute::string("title", 64)],
        any(&["create", "read", "update"]),
        true,
    ))
    .await
    .unwrap();
    let read = Permission::read(&Role::any());
    db.create_document(
        "notes",
        doc(vec![("$id", string("note")), ("$permissions", crate::value::list([string(read.as_str())])), ("title", string("stored"))]),
    )
    .await
    .unwrap();
    adapter.store_attribute(
        "notes",
        "note",
        crate::storage::PERMISSIONS,
        crate::value::list([string(read.as_str()), Value::Int(42), Value::Null, string(read.as_str())]),
    );
    db
}

fn read_any() -> Vec<String> {
    vec![Permission::read(&Role::any())]
}

#[test]
fn reads_drop_a_stored_non_string_permission() {
    block_on(async {
        let db = lenient().await;
        let document = db.get_document("notes", "note", Vec::new(), false).await.unwrap();
        assert_eq!(document.get("title"), Some(&string("stored")));
        assert_eq!(document.permissions().unwrap(), read_any());

        let documents = db.find("notes", Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(documents.len(), 1);
        assert_eq!(documents[0].permissions().unwrap(), read_any());
    });
}

#[test]
fn updates_over_a_stored_non_string_permission() {
    block_on(async {
        let db = lenient().await;
        let updated = db.update_document("notes", "note", doc(vec![("title", string("updated"))])).await.unwrap();
        assert_eq!(updated.get("title"), Some(&string("updated")));
        assert_eq!(updated.permissions().unwrap(), read_any());
        let document = db.get_document("notes", "note", Vec::new(), false).await.unwrap();
        assert_eq!(document.get("title"), Some(&string("updated")));

        let db = lenient().await;
        let count = db
            .update_documents("notes", doc(vec![("title", string("updated"))]), Vec::new(), super::super::BATCH_SIZE, None)
            .await
            .unwrap();
        assert_eq!(count, 1);
        let document = db.get_document("notes", "note", Vec::new(), false).await.unwrap();
        assert_eq!(document.get("title"), Some(&string("updated")));
        assert_eq!(document.permissions().unwrap(), read_any());
    });
}
