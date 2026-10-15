//! PHP: `tests/unit/Documents/DeleteDocumentsRaceTest.php` (8.1.4), its
//! Memory cases: `deleteDocuments()` deletes only the documents that still
//! match its queries when they are deleted. The interleaved write of
//! `Interleaving\Refresh` is [`Tweaked::refresh_once`], which writes through
//! the adapter as PHP's `updateDocument()` from inside `find()` does.
//!
//! Not ported here: the cases that write right before the delete reaches
//! the engine (`beforeDelete`: the contention retries, which need a write
//! detached from the open transaction) and the SQLite, Pool and
//! miscounting-adapter variants; the replayed unit cases cover the adapters'
//! own filtering.

use std::sync::{Arc, Mutex};

use super::s;
use crate::adapter::Adapter;
use crate::attribute::Attribute;
use crate::database::Database;
use crate::database::testing::block_on;
use crate::database::testing_memory::{Tweaked, collection, database_on, doc};
use crate::document::Document;
use crate::permission::{Permission, PermissionType};
use crate::query::Query;
use crate::role::Role;
use crate::value::{Value, list};

const PRESENCES: &str = "presences";
const EXPIRED: &str = "2000-01-01T00:00:00.000+00:00";
const REFRESHED: &str = "2100-01-01T00:00:00.000+00:00";
const NOW: &str = "2050-01-01T00:00:00.000+00:00";

/// `DeleteDocumentsRaceTest::database()`: `presences` with `expiresAt`,
/// creatable by anyone, each document readable and deletable by the owner.
async fn race() -> (Database, Arc<Tweaked>) {
    let engine = Arc::new(Tweaked::new());
    let db = database_on(engine.clone(), "race").await;
    db.authorization().add_role("user:owner");
    collection(&db, PRESENCES, vec![Attribute::datetime("expiresAt")], vec![Permission::create(&Role::any())], true)
        .await;
    (db, engine)
}

/// `seed()`: a presence per id, expiring as given.
async fn seed(db: &Database, presences: &[(&str, &str)]) {
    let owner = Role::user("owner", "");
    for (id, expires) in presences {
        let presence = doc(vec![
            ("$id", s(id)),
            ("expiresAt", s(expires)),
            (
                "$permissions",
                list([s(&Permission::read(&owner)), s(&Permission::update(&owner)), s(&Permission::delete(&owner))]),
            ),
        ]);
        db.create_document(PRESENCES, presence).await.unwrap();
    }
}

/// `expired()`.
fn expired() -> Query {
    Query::less_than("expiresAt", NOW)
}

/// `stored()`: the ids still stored, authorization skipped.
async fn stored(db: &Database) -> Vec<String> {
    let found =
        db.authorization().skip(db.find(PRESENCES, vec![Query::limit(100)], PermissionType::Read)).await.unwrap();
    let mut ids: Vec<String> = found.iter().map(|d| d.id().to_owned()).collect();
    ids.sort();
    ids
}

/// Refreshes `id` the first time a find returns it.
fn refresh_once(engine: &Tweaked, id: &str) {
    let refreshed = crate::datetime::set_timezone(REFRESHED).unwrap();
    engine.refresh_once(PRESENCES, id, "expiresAt", s(&refreshed));
}

/// Deletes the expired presences in batches of `batch_size`; the count and
/// the ids `onNext` was handed.
async fn delete_expired(db: &Database, queries: Vec<Query>, batch_size: i64) -> (i64, Vec<String>) {
    let deleted = Arc::new(Mutex::new(Vec::new()));
    let seen = deleted.clone();
    let mut on_next = move |document: Document| {
        seen.lock().unwrap().push(document.id().to_owned());
        Ok(())
    };
    let count = db.delete_documents(PRESENCES, queries, batch_size, Some(&mut on_next)).await.unwrap();
    let deleted = deleted.lock().unwrap().clone();
    (count, deleted)
}

// PHP: testADocumentChangedAfterTheFindIsKept, `$this->assertSame(2, $count, 'The refreshed document is not counted')`.
#[test]
fn a_document_changed_after_the_find_is_kept() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", EXPIRED), ("c", EXPIRED), ("d", REFRESHED)]).await;
        refresh_once(&engine, "b");

        let (count, deleted) = delete_expired(&db, vec![expired()], 1000).await;

        assert_eq!(count, 2);
        assert_eq!(deleted, ["a", "c"]);
        assert_eq!(stored(&db).await, ["b", "d"]);
    });
}

// PHP: testADocumentChangedAfterALaterBatchIsFoundIsKept, `$this->assertSame(['a', 'b', 'c', 'e'], $deleted)`.
#[test]
fn a_document_changed_after_a_later_batch_is_found_is_kept() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", EXPIRED), ("c", EXPIRED), ("d", EXPIRED), ("e", EXPIRED), ("f", REFRESHED)])
            .await;
        refresh_once(&engine, "d");

        let (count, deleted) = delete_expired(&db, vec![expired()], 2).await;

        assert_eq!(count, 4);
        assert_eq!(deleted, ["a", "b", "c", "e"]);
        assert_eq!(stored(&db).await, ["d", "f"]);
    });
}

// PHP: testTheLimitCountsTheDocumentsFoundSoAKeptDocumentIsNotReplaced,
// `$this->assertSame(['b', 'e'], $this->stored($database), ...)`.
#[test]
fn the_limit_counts_the_documents_found_so_a_kept_document_is_not_replaced() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", EXPIRED), ("c", EXPIRED), ("d", EXPIRED), ("e", EXPIRED)]).await;
        refresh_once(&engine, "b");

        let (count, _) = delete_expired(&db, vec![expired(), Query::limit(4)], 2).await;

        assert_eq!(count, 3);
        assert_eq!(stored(&db).await, ["b", "e"]);
    });
}

// PHP: testABatchFilteredOnlyOnTheSequenceIsNotReadAgain,
// `$this->assertSame(2, $engine->finds - $finds, 'Each batch is found once and not read again')`.
#[test]
fn a_batch_filtered_only_on_the_sequence_is_not_read_again() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", REFRESHED), ("c", EXPIRED)]).await;
        let found = db
            .authorization()
            .skip(db.find(PRESENCES, vec![Query::equal("$id", ["a", "c"])], PermissionType::Read))
            .await
            .unwrap();
        let sequences: Vec<String> = found.iter().map(|d| crate::value::to_text(d.sequence().unwrap())).collect();
        let finds = engine.finds();

        let (count, _) = delete_expired(&db, vec![Query::equal("$sequence", sequences)], 2).await;

        assert_eq!(count, 2);
        assert_eq!(engine.finds() - finds, 2);
        assert_eq!(stored(&db).await, ["b"]);
    });
}

// PHP: testADeleteWithNoRaceDeletesEveryMatchingDocumentAndItsPermissions,
// `$this->assertSame(2, $engine->deletes)`.
#[test]
fn a_delete_with_no_race_deletes_every_matching_document() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", EXPIRED), ("c", EXPIRED), ("d", REFRESHED)]).await;

        let (count, deleted) = delete_expired(&db, vec![expired()], 2).await;

        assert_eq!(count, 3);
        assert_eq!(deleted, ["a", "b", "c"]);
        assert_eq!(engine.deletes(), 2);
        assert_eq!(stored(&db).await, ["d"]);
    });
}

// PHP: testADeleteWithoutFiltersDeletesEveryDocument,
// `$this->assertSame(2, $engine->finds - $finds, 'Each batch is found once and not read again')`.
#[test]
fn a_delete_without_filters_deletes_every_document() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", REFRESHED), ("c", EXPIRED)]).await;
        let finds = engine.finds();

        let (count, _) = delete_expired(&db, Vec::new(), 2).await;

        assert_eq!(count, 3);
        assert_eq!(engine.finds() - finds, 2);
        assert_eq!(engine.deletes(), 2);
        assert!(stored(&db).await.is_empty());
    });
}

// PHP: testTheAdapterDeletesOnlyTheRowsThatStillMatchAndKeepsTheirPermissions,
// `$this->assertSame(['b', 'd'], ..., 'The kept row keeps its permission rows')`.
#[test]
fn the_adapter_deletes_only_the_rows_that_still_match_and_keeps_their_permissions() {
    block_on(async {
        let (db, engine) = race().await;
        seed(&db, &[("a", EXPIRED), ("b", EXPIRED), ("c", EXPIRED), ("d", REFRESHED)]).await;
        let found = db.find(PRESENCES, vec![expired()], PermissionType::Read).await.unwrap();
        let refreshed = doc(vec![("expiresAt", s(REFRESHED))]);
        db.update_document(PRESENCES, "b", refreshed).await.unwrap();

        let collection = db.get_collection(PRESENCES).await.unwrap();
        let sequences: Vec<Value> = found.iter().filter_map(|d| d.sequence().cloned()).collect();
        let permission_ids: Vec<String> = found.iter().map(|d| d.id().to_owned()).collect();
        let filters = db.convert_queries(&collection, vec![expired()], &[]).unwrap();

        let count = engine.delete_documents_matching(&collection, sequences, permission_ids, filters).await.unwrap();

        assert_eq!(count, 2);
        assert_eq!(stored(&db).await, ["b", "d"]);
        let readable = db.find(PRESENCES, Vec::new(), PermissionType::Read).await.unwrap();
        assert_eq!(readable.iter().map(Document::id).collect::<Vec<_>>(), ["b", "d"]);
    });
}
