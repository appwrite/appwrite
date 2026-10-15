//! `MongoSkipDuplicatesTest`, `MongoUpsertTenantTest`, `MongoSequencesTest`,
//! `MongoSharedTablesInsertTest`, `MongoIdentifierTest`,
//! `MongoLenientReadTest`, `MongoCursorTest`, and the MongoDB cases of
//! `UniqueViolationTest`, `EngineErrorMappingTest` and `BigIntegerTest`.

use std::sync::{Arc, Mutex};

use utopia_database::adapter::Find;
use utopia_database::adapter::mongo::{Mongo, process_exception};
use utopia_database::change::Change;
use utopia_database::collection::Collection;
use utopia_database::value::Value;
use utopia_database::{Adapter, Document, Error, Kind};
use utopia_mongo::bson::oid::ObjectId;
use utopia_mongo::bson::{Bson, Document as BsonDocument, doc};

use crate::common::{assert_error, assert_error_contains, doc, s};
use crate::fake::{Fake, at, batch, error};

fn collection(id: &str) -> Collection {
    Collection::from_document(&Document::from_pairs([("$id", s(id))]).unwrap()).unwrap()
}

/// Folds case and accents as the `_uid` index collation does.
fn fold(value: &Bson, folded: bool) -> Bson {
    match value {
        Bson::String(s) if folded => Bson::String(s.replace('é', "e").replace('É', "E").to_lowercase()),
        other => other.clone(),
    }
}

/// Whether `row` matches `filters`: each field equal to the condition, or
/// to one of its `$in` values.
fn matches(row: &BsonDocument, filters: &BsonDocument, folded: bool) -> bool {
    filters.iter().all(|(field, condition)| {
        let candidates: Vec<Bson> = match condition {
            Bson::Document(d) => match d.get("$in") {
                Some(Bson::Array(items)) => items.clone(),
                _ => vec![condition.clone()],
            },
            other => vec![other.clone()],
        };
        let value = fold(row.get(field).unwrap_or(&Bson::Null), folded);
        candidates.iter().any(|c| fold(c, folded) == value)
    })
}

// ---- MongoSkipDuplicatesTest

type Rows = Arc<Mutex<Vec<BsonDocument>>>;

fn row(id: &str, sequence: &str, tenant: Option<i32>) -> BsonDocument {
    doc! { "_uid": id, "_id": sequence, "_tenant": tenant.map_or(Bson::Null, Bson::Int32) }
}

/// A driver that applies an upsert as MongoDB does: a statement whose
/// filter matches a stored document under the `_uid` collation changes
/// nothing, any other inserts its `$setOnInsert` document.
async fn skip_adapter(rows: &Rows, shared: bool, before_update: Option<Box<dyn Fn() + Send + Sync>>) -> Mongo {
    let fake = Fake::new();
    let found = rows.clone();
    fake.on("find", move |args| {
        let folded = at(&args[2], "collation.strength") == Some(&Bson::Int32(1));
        let filters = match &args[1] {
            Bson::Document(d) => d.clone(),
            _ => BsonDocument::new(),
        };
        let rows: Vec<BsonDocument> =
            found.lock().unwrap().iter().filter(|r| matches(r, &filters, folded)).cloned().collect();
        Ok(batch(rows))
    });
    let stored = rows.clone();
    fake.on("query", move |args| {
        let Some(Bson::Array(updates)) = at(&args[0], "updates") else {
            panic!("An upsert command must list its updates")
        };
        if let Some(hook) = &before_update {
            hook();
        }
        for update in updates {
            assert_eq!(
                at(update, "collation.strength"),
                Some(&Bson::Int32(1)),
                "An upsert by id must use the _uid index collation"
            );
            let Some(Bson::Document(q)) = at(update, "q") else { panic!("An upsert must filter") };
            assert!(matches!(q.get("_uid"), Some(Bson::String(_))), "An upsert by id must filter on _uid");
            if stored.lock().unwrap().iter().any(|r| matches(r, q, true)) {
                continue;
            }
            let mut row = q.clone();
            if let Some(Bson::Document(insert)) = at(update, "u.$setOnInsert") {
                for (k, v) in insert {
                    row.insert(k.clone(), v.clone());
                }
            }
            stored.lock().unwrap().push(row);
        }
        Ok(Bson::Int32(updates.len() as i32))
    });
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("skip_duplicates");
    adapter.set_shared_tables(shared);
    adapter
}

async fn ignoring(adapter: &Mongo, documents: Vec<Document>) -> Vec<Document> {
    adapter
        .state()
        .ignoring_duplicates()
        .with(true, adapter.create_documents(&collection("orders"), documents))
        .await
        .unwrap()
}

fn ids(documents: &[Document]) -> Vec<String> {
    documents.iter().map(|d| d.id().to_owned()).collect()
}

fn stored_ids(rows: &Rows) -> Vec<String> {
    rows.lock().unwrap().iter().map(|r| r.get_str("_uid").unwrap().to_owned()).collect()
}

#[tokio::test]
async fn only_documents_with_a_new_id_are_written_and_returned() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("stored", "sequence-stored", None)]));
    let adapter = skip_adapter(&rows, false, None).await;

    let created = ignoring(
        &adapter,
        vec![
            doc(r#"{"$id":"stored","name":"replayed"}"#),
            doc(r#"{"$id":"fresh","name":"first"}"#),
            doc(r#"{"$id":"fresh","name":"second"}"#),
        ],
    )
    .await;

    assert_eq!(ids(&created), vec!["fresh"]);
    assert_eq!(created[0].get("name"), Some(&s("first")));
    assert_eq!(stored_ids(&rows), vec!["stored", "fresh"]);
    assert_eq!(rows.lock().unwrap()[1].get_str("name").ok(), Some("first"));
}

#[tokio::test]
async fn an_id_stored_under_another_tenant_is_new() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("shared", "sequence-one", Some(1))]));
    let adapter = skip_adapter(&rows, true, None).await;

    let created = ignoring(
        &adapter,
        vec![
            doc(r#"{"$id":"shared","$tenant":1,"name":"replayed"}"#),
            doc(r#"{"$id":"shared","$tenant":2,"name":"new"}"#),
        ],
    )
    .await;

    assert_eq!(created.iter().map(Document::tenant).collect::<Vec<_>>(), vec![Some(Value::Int(2))]);
    assert_eq!(stored_ids(&rows), vec!["shared", "shared"]);
}

#[tokio::test]
async fn an_id_differing_only_in_case_is_stored() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("Stored", "sequence-stored", None)]));
    let adapter = skip_adapter(&rows, false, None).await;

    let created = ignoring(
        &adapter,
        vec![
            doc(r#"{"$id":"stored","name":"replayed"}"#),
            doc(r#"{"$id":"Fresh","name":"first"}"#),
            doc(r#"{"$id":"fresh","name":"second"}"#),
        ],
    )
    .await;

    assert_eq!(ids(&created), vec!["Fresh"]);
    assert_eq!(stored_ids(&rows), vec!["Stored", "Fresh"]);
}

#[tokio::test]
async fn an_id_the_id_collation_matches_is_not_reported_as_created() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("resume", "sequence-stored", None)]));
    let adapter = skip_adapter(&rows, false, None).await;

    let created = ignoring(&adapter, vec![doc(r#"{"$id":"résumé","name":"replayed"}"#)]).await;

    assert!(
        created.is_empty(),
        "The _uid index collation folds accents, so the upsert matched the stored document and inserted nothing"
    );
    assert_eq!(stored_ids(&rows), vec!["resume"]);
}

#[tokio::test]
async fn a_document_another_writer_stores_first_is_not_reported_as_created() {
    let rows: Rows = Arc::default();
    let racing = rows.clone();
    let hook: Box<dyn Fn() + Send + Sync> =
        Box::new(move || racing.lock().unwrap().push(row("raced", "sequence-other-writer", None)));
    let adapter = skip_adapter(&rows, false, Some(hook)).await;

    let created =
        ignoring(&adapter, vec![doc(r#"{"$id":"raced","name":"late"}"#), doc(r#"{"$id":"fresh","name":"new"}"#)]).await;

    assert_eq!(
        ids(&created),
        vec!["fresh"],
        "A document another writer stored before the upsert ran was matched, not inserted"
    );
    assert_eq!(stored_ids(&rows), vec!["raced", "fresh"]);
}

#[tokio::test]
async fn a_replayed_sequence_is_not_reported_as_created() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("stored", "sequence-stored", None)]));
    let adapter = skip_adapter(&rows, false, None).await;

    let created = ignoring(
        &adapter,
        vec![
            doc(r#"{"$id":"stored","$sequence":"sequence-stored","name":"replayed"}"#),
            doc(r#"{"$id":"moved","$sequence":"sequence-new","name":"new"}"#),
        ],
    )
    .await;

    assert_eq!(ids(&created), vec!["moved"]);
    assert_eq!(stored_ids(&rows), vec!["stored", "moved"]);
}

#[tokio::test]
async fn a_batch_of_stored_ids_writes_nothing() {
    let rows: Rows = Arc::new(Mutex::new(vec![row("stored", "sequence-stored", None)]));
    let adapter = skip_adapter(&rows, false, None).await;

    let created = ignoring(&adapter, vec![doc(r#"{"$id":"stored","name":"replayed"}"#)]).await;

    assert!(created.is_empty());
    assert_eq!(stored_ids(&rows), vec!["stored"]);
    assert!(rows.lock().unwrap()[0].get("name").is_none(), "A skipped document leaves the stored one untouched");
}

// ---- MongoUpsertTenantTest

async fn upsert_adapter(shared: bool, tenant: Option<i64>) -> (Mongo, Arc<Fake>) {
    let fake = Fake::new();
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    adapter.set_namespace("tenants");
    adapter.set_shared_tables(shared);
    adapter.set_tenant(tenant.map_or(Value::Null, Value::Int));
    (adapter, fake)
}

fn change(id: &str, tenant: Option<i64>) -> Change {
    let mut document = doc(&format!(
        r#"{{"$id":"{id}","$createdAt":"2026-01-01 00:00:00.000","$updatedAt":"2026-01-01 00:00:00.000","$permissions":[],"name":"renamed"}}"#
    ));
    if let Some(tenant) = tenant {
        document.set("$tenant", Value::Int(tenant)).unwrap();
    }
    Change::new(Document::empty(), document)
}

fn operation(fake: &Fake, index: usize, path: &str) -> Option<Bson> {
    at(&fake.args("upsert")[0][1], &format!("{index}.{path}")).cloned()
}

#[tokio::test]
async fn upsert_stamps_and_matches_the_documents_own_tenant() {
    let (adapter, fake) = upsert_adapter(true, Some(6)).await;

    adapter
        .upserts()
        .unwrap()
        .upsert_documents(&collection("orders"), vec![change("shared", Some(5))], None)
        .await
        .unwrap();

    assert_eq!(operation(&fake, 0, "filter._tenant"), Some(Bson::Int32(5)));
    assert_eq!(operation(&fake, 0, "update.$set._tenant"), Some(Bson::Int32(5)));
}

#[tokio::test]
async fn upsert_falls_back_to_the_selected_tenant() {
    let (adapter, fake) = upsert_adapter(true, Some(3)).await;

    adapter.upserts().unwrap().upsert_documents(&collection("orders"), vec![change("own", None)], None).await.unwrap();

    assert_eq!(operation(&fake, 0, "filter._tenant"), Some(Bson::Int32(3)));
    assert_eq!(operation(&fake, 0, "update.$set._tenant"), Some(Bson::Int32(3)));
}

#[tokio::test]
async fn upsert_keeps_each_document_under_its_tenant() {
    let (adapter, fake) = upsert_adapter(true, None).await;

    adapter
        .upserts()
        .unwrap()
        .upsert_documents(&collection("orders"), vec![change("shared", Some(1)), change("shared", Some(2))], None)
        .await
        .unwrap();

    assert_eq!(
        [operation(&fake, 0, "filter._tenant"), operation(&fake, 1, "filter._tenant")],
        [Some(Bson::Int32(1)), Some(Bson::Int32(2))]
    );
}

#[tokio::test]
async fn upsert_without_shared_tables_stamps_no_tenant() {
    let (adapter, fake) = upsert_adapter(false, None).await;

    adapter
        .upserts()
        .unwrap()
        .upsert_documents(&collection("orders"), vec![change("single", None)], None)
        .await
        .unwrap();

    assert!(operation(&fake, 0, "filter._tenant").is_none());
    assert!(operation(&fake, 0, "update.$set._tenant").is_none());
}

// ---- MongoSequencesTest

async fn sequences_adapter(shared: bool, tenant: Option<i64>, rows: Vec<BsonDocument>) -> (Mongo, Arc<Fake>) {
    let fake = Fake::new();
    fake.on("find", move |args| {
        let filters = match &args[1] {
            Bson::Document(d) => d.clone(),
            _ => BsonDocument::new(),
        };
        let projection = match at(&args[2], "projection") {
            Some(Bson::Document(p)) => Some(p.clone()),
            _ => None,
        };
        let batch_rows = rows
            .iter()
            .filter(|r| matches(r, &filters, false))
            .map(|r| match &projection {
                Some(p) => r
                    .iter()
                    .filter(|(k, _)| p.contains_key(k.as_str()) || k.as_str() == "_id")
                    .map(|(k, v)| (k.clone(), v.clone()))
                    .collect(),
                None => r.clone(),
            })
            .collect();
        Ok(batch(batch_rows))
    });
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    adapter.set_namespace("sequences");
    adapter.set_shared_tables(shared);
    adapter.set_tenant(tenant.map_or(Value::Null, Value::Int));
    (adapter, fake)
}

fn sequence_row(id: &str, sequence: Bson, tenant: Option<i32>) -> BsonDocument {
    doc! { "_uid": id, "_id": sequence, "_tenant": tenant.map_or(Bson::Null, Bson::Int32), "name": "unprojected" }
}

#[tokio::test]
async fn sequences_resolve_per_tenant_for_the_same_id() {
    let rows = vec![
        sequence_row("shared", "sequence-one".into(), Some(1)),
        sequence_row("shared", "sequence-two".into(), Some(2)),
    ];
    let (adapter, _) = sequences_adapter(true, None, rows).await;

    let documents = adapter
        .get_sequences(
            &collection("orders"),
            vec![doc(r#"{"$id":"shared","$tenant":1}"#), doc(r#"{"$id":"shared","$tenant":2}"#)],
        )
        .await
        .unwrap();

    assert_eq!(documents[0].sequence(), Some(&s("sequence-one")));
    assert_eq!(documents[1].sequence(), Some(&s("sequence-two")));
}

#[tokio::test]
async fn document_without_tenant_resolves_under_the_adapter_tenant() {
    let rows = vec![
        sequence_row("first", "sequence-first".into(), Some(3)),
        sequence_row("second", "sequence-second".into(), Some(5)),
    ];
    let (adapter, _) = sequences_adapter(true, Some(3), rows).await;

    let documents = adapter
        .get_sequences(&collection("orders"), vec![doc(r#"{"$id":"first"}"#), doc(r#"{"$id":"second","$tenant":5}"#)])
        .await
        .unwrap();

    assert_eq!(documents[0].sequence(), Some(&s("sequence-first")));
    assert_eq!(documents[1].sequence(), Some(&s("sequence-second")));
}

#[tokio::test]
async fn sequence_resolves_only_within_the_document_tenant() {
    let (adapter, _) =
        sequences_adapter(true, Some(1), vec![sequence_row("shared", "sequence-other".into(), Some(2))]).await;

    let documents =
        adapter.get_sequences(&collection("orders"), vec![doc(r#"{"$id":"shared","$tenant":1}"#)]).await.unwrap();

    assert_eq!(documents[0].sequence(), None);
}

#[tokio::test]
async fn generated_object_id_is_read_as_its_string() {
    let object_id = ObjectId::parse_str("6553f1c2a4b8e3d2f0c1a9b7").unwrap();
    let (adapter, _) =
        sequences_adapter(false, None, vec![sequence_row("generated", Bson::ObjectId(object_id), None)]).await;

    let documents = adapter.get_sequences(&collection("orders"), vec![doc(r#"{"$id":"generated"}"#)]).await.unwrap();

    assert_eq!(documents[0].sequence(), Some(&s("6553f1c2a4b8e3d2f0c1a9b7")));
}

#[tokio::test]
async fn documents_with_sequences_are_not_looked_up() {
    let (adapter, fake) = sequences_adapter(false, None, vec![]).await;

    let documents = adapter
        .get_sequences(&collection("orders"), vec![doc(r#"{"$id":"known","$sequence":"sequence-known"}"#)])
        .await
        .unwrap();

    assert_eq!(documents[0].sequence(), Some(&s("sequence-known")));
    assert!(fake.args("find").is_empty());
}

// ---- MongoSharedTablesInsertTest

async fn insert_adapter(shared: bool) -> Mongo {
    let fake = Fake::new();
    let rows: Arc<Mutex<Vec<(String, BsonDocument)>>> = Arc::default();
    let inserted = rows.clone();
    let uuids = fake.clone();
    fake.on("insert", move |args| {
        let mut document = match &args[1] {
            Bson::Document(d) => d.clone(),
            _ => BsonDocument::new(),
        };
        if !document.contains_key("_id") {
            document.insert("_id", utopia_database::adapter::mongo::Driver::create_uuid(uuids.as_ref()));
        }
        inserted.lock().unwrap().push((args[0].as_str().unwrap().to_owned(), document.clone()));
        Ok(Bson::Document(document))
    });
    fake.on("find", move |args| {
        let collection = args[0].as_str().unwrap();
        let Bson::Document(filters) = &args[1] else { return Ok(batch(vec![])) };
        let found = rows
            .lock()
            .unwrap()
            .iter()
            .filter(|(c, r)| c == collection && filters.iter().all(|(k, v)| r.get(k).unwrap_or(&Bson::Null) == v))
            .map(|(_, r)| r.clone())
            .collect();
        Ok(batch(found))
    });
    let adapter = crate::fake::adapter(&fake, "tenants", false).await;
    adapter.set_shared_tables(shared);
    adapter
}

fn user(tenant: i64, email: &str, secret: &str) -> Document {
    doc(&format!(r#"{{"$id":"alice","$tenant":{tenant},"$permissions":[],"email":"{email}","secret":"{secret}"}}"#))
}

#[tokio::test]
async fn create_document_returns_the_creating_tenants_document_for_an_id_another_tenant_holds() {
    let adapter = insert_adapter(true).await;
    let users = collection("users");

    adapter.set_tenant(Value::Int(1));
    let first = adapter.create_document(&users, user(1, "first@tenant", "first")).await.unwrap();
    adapter.set_tenant(Value::Int(2));
    let second = adapter.create_document(&users, user(2, "second@tenant", "second")).await.unwrap();

    assert_eq!(second.tenant(), Some(Value::Int(2)));
    assert_eq!(second.get("email"), Some(&s("second@tenant")));
    assert_eq!(second.get("secret"), Some(&s("second")));
    assert_ne!(first.sequence(), second.sequence());
    assert_eq!(first.tenant(), Some(Value::Int(1)));
    assert_eq!(first.get("email"), Some(&s("first@tenant")));
}

#[tokio::test]
async fn create_document_returns_the_created_document_without_shared_tables() {
    let adapter = insert_adapter(false).await;

    let created = adapter
        .create_document(&collection("users"), doc(r#"{"$id":"alice","$permissions":[],"email":"only@tenant"}"#))
        .await
        .unwrap();

    assert_eq!(created.get("email"), Some(&s("only@tenant")));
    assert!(created.sequence().is_some_and(|s| s.truthy()));
}

// ---- MongoIdentifierTest

#[tokio::test]
async fn get_document_reads_an_object_sequence_as_its_string() {
    let fake = Fake::new();
    let object_id = ObjectId::parse_str("507f1f77bcf86cd799439011").unwrap();
    fake.on("find", move |_| Ok(batch(vec![doc! { "_id": object_id, "_uid": "movies" }])));
    let adapter = Mongo::with_driver(fake).await.unwrap();

    let document = adapter.get_document(&collection("movies"), "movies", vec![], false).await.unwrap();

    assert_eq!(document.get("$sequence"), Some(&s("507f1f77bcf86cd799439011")));
    assert_eq!(document.id(), "movies");
    assert!(!document.has("_id"));
}

// ---- MongoLenientReadTest

fn lenient_row(id: &str) -> BsonDocument {
    doc! { "_uid": id, "_permissions": ["read(\"any\")", 42, Bson::Null, "read(\"any\")"], "title": "stored" }
}

async fn lenient_adapter(first: Vec<BsonDocument>, next: Vec<BsonDocument>) -> Mongo {
    let fake = Fake::new();
    let more = !next.is_empty();
    fake.on("find", move |_| {
        Ok(Bson::Document(doc! { "cursor": { "firstBatch": first.clone(), "id": if more { 1 } else { 0 } } }))
    });
    fake.on("getMore", move |_| Ok(Bson::Document(doc! { "cursor": { "nextBatch": next.clone(), "id": 0 } })));
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("lenient");
    adapter
}

#[tokio::test]
async fn get_document_drops_a_stored_non_string_permission() {
    let adapter = lenient_adapter(vec![lenient_row("note")], vec![]).await;

    let document = adapter.get_document(&collection("notes"), "note", vec![], false).await.unwrap();

    assert_eq!(document.id(), "note");
    assert_eq!(document.permissions().unwrap(), vec!["read(\"any\")"]);
}

#[tokio::test]
async fn find_drops_a_stored_non_string_permission_in_every_batch() {
    let adapter = lenient_adapter(vec![lenient_row("first")], vec![lenient_row("second")]).await;

    let documents = adapter.find(&collection("notes"), vec![], Find::default()).await.unwrap();

    assert_eq!(documents.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), vec!["first", "second"]);
    for document in documents {
        assert_eq!(document.permissions().unwrap(), vec!["read(\"any\")"]);
    }
}

// ---- MongoCursorTest

async fn cursor_adapter(first: Vec<BsonDocument>, next: Vec<Vec<BsonDocument>>) -> (Mongo, Arc<Mutex<Vec<String>>>) {
    let fake = Fake::new();
    let calls: Arc<Mutex<Vec<String>>> = Arc::default();
    fake.on("find", move |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": first.clone(), "id": 7 } })));
    let more = calls.clone();
    fake.on("getMore", move |_| {
        let mut calls = more.lock().unwrap();
        let batch = next.get(calls.len()).cloned().unwrap_or_default();
        calls.push("getMore".into());
        Ok(Bson::Document(doc! { "cursor": { "nextBatch": batch } }))
    });
    let killed = calls.clone();
    fake.on("query", move |args| {
        let first = match &args[0] {
            Bson::Document(d) => d.keys().next().cloned().unwrap_or_default(),
            _ => String::new(),
        };
        killed.lock().unwrap().push(if first == "killCursors" { first } else { "query".into() });
        Err(error("the cursor could not be killed", 0))
    });
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("cursor");
    (adapter, calls)
}

fn cursor_row(id: &str, sequence: &str) -> BsonDocument {
    doc! { "_uid": id, "_id": sequence }
}

#[tokio::test]
async fn sequences_stop_paging_at_a_batch_without_a_cursor_id() {
    let (adapter, calls) = cursor_adapter(
        vec![cursor_row("first", "sequence-first")],
        vec![vec![cursor_row("second", "sequence-second")], vec![cursor_row("third", "sequence-third")]],
    )
    .await;

    let documents = adapter
        .get_sequences(
            &collection("orders"),
            vec![doc(r#"{"$id":"first"}"#), doc(r#"{"$id":"second"}"#), doc(r#"{"$id":"third"}"#)],
        )
        .await
        .unwrap();

    assert_eq!(documents[0].sequence(), Some(&s("sequence-first")));
    assert_eq!(documents[1].sequence(), Some(&s("sequence-second")));
    assert_eq!(documents[2].sequence(), None, "a batch without a cursor id is the last one");
    assert_eq!(*calls.lock().unwrap(), vec!["getMore"]);
}

#[tokio::test]
async fn a_find_keeps_its_results_when_the_open_cursor_cannot_be_killed() {
    let (adapter, calls) = cursor_adapter(vec![cursor_row("first", "sequence-first")], vec![vec![]]).await;

    let documents = adapter.find(&collection("orders"), vec![], Find::default()).await.unwrap();

    assert_eq!(documents.iter().map(|d| d.id().to_owned()).collect::<Vec<_>>(), vec!["first"]);
    assert_eq!(
        *calls.lock().unwrap(),
        vec!["getMore", "killCursors"],
        "the cursor left open by an empty batch is killed, and the failure to kill it is ignored"
    );
}

// ---- UniqueViolationTest (MongoDB)

async fn mongo_create_failure(failure: utopia_mongo::Error) -> Error {
    let fake = Fake::new();
    fake.on("insert", move |_| Err(failure.clone()));
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("unique_violation");
    let movie = doc(r#"{"$id":"movie-1","$permissions":["read(\"any\")"],"slug":"prefix_uid_suffix"}"#);
    adapter.create_document(&collection("movies"), movie).await.unwrap_err()
}

fn assert_duplicate(e: Error) {
    assert!(e.is_a(Kind::Duplicate), "{}", e.message());
    assert!(!e.is_a(Kind::Unique));
    assert_eq!(e.message(), "Document already exists");
}

fn assert_unique(e: Error) {
    assert!(e.is_a(Kind::Unique), "{}", e.message());
    assert_eq!(e.message(), "Unique index violation");
}

#[tokio::test]
async fn mongo_document_id_conflict_is_duplicate() {
    assert_duplicate(
        mongo_create_failure(error(
            "E11000 duplicate key error collection: db.ns_movies index: _uid dup key: { _uid: \"movie-1\" }",
            11000,
        ))
        .await,
    );
}

#[tokio::test]
async fn mongo_unique_index_conflict_with_uid_in_value_is_unique() {
    assert_unique(
        mongo_create_failure(error(
            "E11000 duplicate key error collection: db.ns_movies index: slug dup key: { slug: \"prefix_uid_suffix\" }",
            11000,
        ))
        .await,
    );
}

#[tokio::test]
async fn mongo_unparsable_message_is_duplicate() {
    assert_duplicate(mongo_create_failure(error("E11000 duplicate key error", 11000)).await);
}

// ---- EngineErrorMappingTest (MongoDB)

#[test]
fn mongo_type_mismatch_is_an_invalid_operation() {
    let error = Error::new(
        Kind::Mongo,
        "Cannot apply $inc to a value of non-numeric type. {_id: ObjectId('66f9')} has the field 'name' of non-numeric type string",
    )
    .with_code(14);

    let mapped = process_exception(error.clone());

    assert_eq!(mapped.kind(), Kind::Type);
    assert_eq!(mapped.message(), "Invalid operation");
    assert_eq!(mapped.previous(), Some(&error));
}

// ---- BigIntegerTest (MongoDB)

#[tokio::test]
async fn mongo_rejects_unsigned_arithmetic_before_bson_coercion() {
    let fake = Fake::new();
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();

    let result = adapter
        .increase_document_attribute(
            &collection("collection"),
            "document",
            "value",
            s(utopia_database::validator::big_int::UNSIGNED_MAX),
            "2026-01-01T00:00:00.000+00:00",
            None,
            None,
        )
        .await;

    assert_error_contains(result, Kind::Type, "outside the signed 64-bit integer range");
    assert!(fake.args("query").is_empty());
}

#[tokio::test]
async fn increments_add_within_the_bounds_by_a_pipeline_update() {
    let fake = Fake::new();
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    adapter.set_namespace("counters");

    adapter
        .increase_document_attribute(
            &collection("stats"),
            "document",
            "value",
            s("9223372036854775807"),
            "2026-01-01 00:00:00.000",
            Some(s("-9223372036854775808")),
            Some(Value::Int(100)),
        )
        .await
        .unwrap();

    let command = fake.args("query").remove(0).remove(0);
    let current = doc! { "$ifNull": ["$value", 0] };
    assert_eq!(
        command,
        Bson::Document(doc! {
            "update": "counters_stats",
            "updates": [{
                "q": {
                    "_uid": "document",
                    "$expr": { "$and": [
                        { "$lte": [current.clone(), 100] },
                        { "$gte": [current.clone(), i64::MIN] },
                    ] },
                },
                "u": [{ "$set": {
                    "value": { "$add": [current, i64::MAX] },
                    "_updatedAt": { "$literal": utopia_mongo::bson::DateTime::from_millis(1_767_225_600_000) },
                } }],
                "multi": false,
                "upsert": false,
            }],
        })
    );
    assert_error(
        adapter
            .increase_document_attribute(
                &collection("stats"),
                "document",
                "value",
                Value::Int(1),
                "2026-01-01",
                Some(s("-9223372036854775809")),
                None,
            )
            .await,
        Kind::Type,
        "MongoDB cannot safely apply minimum outside the signed 64-bit integer range.",
    );
}
