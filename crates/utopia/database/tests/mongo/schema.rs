//! `MongoCreateCollectionTest`, `MongoUniqueIndexWaitTest`,
//! `MongoDatabaseExistsTest`, `MongoDatabaseRenameTest` and
//! `MongoNameFilterTest`.

use std::collections::BTreeMap;
use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use utopia_database::adapter::mongo::{DriverResult, Mongo};
use utopia_database::attribute::Attribute;
use utopia_database::{Adapter, Error, Index, Kind};
use utopia_mongo::bson::{Bson, doc};

use crate::common::assert_error;
use crate::fake::{Fake, at, error};

const SERVER_EXISTS: &str = "Collection utopiaTests.engine_orders already exists.";

// ---- MongoCreateCollectionTest

/// An adapter whose `createCollection` fails with `failure`, counting the
/// `createIndexes` calls.
async fn create_adapter(failure: utopia_mongo::Error, shared: bool) -> (Mongo, Arc<Fake>) {
    let fake = Fake::new();
    fake.on("query", |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": [], "id": 0 } })));
    fake.on("createCollection", move |_| Err(failure.clone()));
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    adapter.set_namespace("engine");
    adapter.set_shared_tables(shared);
    (adapter, fake)
}

fn title() -> (Vec<Attribute>, Vec<Index>) {
    (vec![Attribute::string("title", 64)], vec![Index::key("title", ["title"], vec![], vec![]).unwrap()])
}

#[utopia_async::test]
async fn an_existing_shared_collection_gets_its_indexes_and_is_reported_as_existing() {
    let cases = [
        (error("Collection Exists", 48), "orders", true),
        (error(SERVER_EXISTS, 48), "orders", true),
        (error(SERVER_EXISTS, 48), "_metadata", false),
    ];
    for (failure, name, shared) in cases {
        let (adapter, fake) = create_adapter(failure, shared).await;
        let (attributes, indexes) = title();

        let result = adapter.create_collection(name, &attributes, &indexes).await;

        assert!(
            result.is_err_and(|e| e.is_a(Kind::Duplicate)),
            "An adopted collection must be reported as existing, so its creator alone may drop it"
        );
        assert_eq!(
            fake.args("createIndexes").len(),
            2,
            "A shared collection another creator left without its indexes must get them"
        );
    }
}

#[utopia_async::test]
async fn an_existing_collection_is_duplicate_outside_shared_tables() {
    for failure in [error("Collection Exists", 48), error("Collection Exists", 0), error(SERVER_EXISTS, 48)] {
        let (adapter, fake) = create_adapter(failure.clone(), false).await;

        let thrown = adapter.create_collection("orders", &[], &[]).await.unwrap_err();

        assert!(thrown.is_a(Kind::Duplicate));
        assert_eq!(thrown.message(), "Collection already exists");
        assert_eq!(thrown.previous(), Some(&utopia_database::adapter::mongo::from_client(failure)));
        assert!(fake.args("createIndexes").is_empty(), "A collection this call did not create must not be adopted");
    }
}

#[utopia_async::test]
async fn other_errors_are_rethrown() {
    let failure = error("not authorized on utopiaTests to execute command", 13);
    let (adapter, _) = create_adapter(failure.clone(), true).await;

    let thrown = adapter.create_collection("orders", &[], &[]).await.unwrap_err();

    assert_eq!(thrown, utopia_database::adapter::mongo::from_client(failure));
}

async fn index_failing_adapter(failure: utopia_mongo::Error, failing_call: i64) -> Mongo {
    let fake = Fake::new();
    let calls = AtomicI64::new(0);
    fake.on("createIndexes", move |_| {
        if calls.fetch_add(1, Ordering::Relaxed) + 1 == failing_call {
            return Err(failure.clone());
        }
        Ok(Bson::Boolean(true))
    });
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("engine");
    adapter
}

#[utopia_async::test]
async fn a_failure_creating_the_internal_indexes_is_mapped() {
    let failure = error("operation exceeded time limit", 50);

    let thrown =
        index_failing_adapter(failure.clone(), 1).await.create_collection("orders", &[], &[]).await.unwrap_err();

    assert_eq!(thrown.kind(), Kind::Timeout);
    assert_eq!(thrown.previous(), Some(&utopia_database::adapter::mongo::from_client(failure)));
}

#[utopia_async::test]
async fn a_conflict_creating_the_declared_indexes_is_an_index_error() {
    let failure = error("Index with name: title already exists with different options", 85);
    let (attributes, indexes) = title();

    let thrown = index_failing_adapter(failure.clone(), 2)
        .await
        .create_collection("orders", &attributes, &indexes)
        .await
        .unwrap_err();

    assert_eq!(thrown.kind(), Kind::Index);
    assert_eq!(thrown.message(), "Index already exists");
    assert_eq!(thrown.previous(), Some(&utopia_database::adapter::mongo::from_client(failure)));
}

type CreateIndexes = Arc<dyn Fn(i64) -> DriverResult<Bson> + Send + Sync>;

/// `indexesNotCreated()`.
fn indexes_not_created() -> Vec<(&'static str, CreateIndexes, Vec<Index>)> {
    let title = || vec![Index::key("title", ["title"], vec![], vec![]).unwrap()];
    vec![
        ("the internal indexes are not created", Arc::new(|_| Ok(Bson::Boolean(false))), vec![]),
        ("the internal indexes fail", Arc::new(|_| Err(error("not authorized", 13))), vec![]),
        ("the declared indexes are not created", Arc::new(|call| Ok(Bson::Boolean(call == 1))), title()),
        (
            "the declared indexes fail",
            Arc::new(|call| if call == 1 { Ok(Bson::Boolean(true)) } else { Err(error("not authorized", 13)) }),
            title(),
        ),
        (
            "a declared index of an unsupported type",
            Arc::new(|_| Ok(Bson::Boolean(true))),
            vec![Index::spatial("title", "title", None).unwrap()],
        ),
    ]
}

/// The collections dropped after a create whose indexes were not created.
async fn dropped_after_a_failed_create(
    create_indexes: CreateIndexes,
    indexes: Vec<Index>,
    shared: bool,
    in_transaction: bool,
) -> Vec<Bson> {
    let fake = Fake::new();
    let calls = AtomicI64::new(0);
    fake.on("createIndexes", move |_| create_indexes(calls.fetch_add(1, Ordering::Relaxed) + 1));
    fake.on("query", |_| Ok(Bson::Document(doc! { "cursor": { "firstBatch": [] } })));
    let adapter = Mongo::with_driver(fake.clone()).await.unwrap();
    adapter.set_namespace("engine");
    adapter.set_shared_tables(shared);
    if in_transaction {
        adapter.state().lock().in_transaction = 1;
    }

    if let Ok(created) = adapter.create_collection("orders", &[Attribute::string("title", 64)], &indexes).await {
        assert!(!created);
    }

    fake.args("dropCollection").into_iter().map(|a| a[0].clone()).collect()
}

#[utopia_async::test]
async fn a_collection_whose_indexes_are_not_created_is_dropped() {
    for (name, create_indexes, indexes) in indexes_not_created() {
        assert_eq!(
            dropped_after_a_failed_create(create_indexes, indexes, false, false).await,
            vec![Bson::String("engine_orders".into())],
            "{name}: A collection this call created without its indexes must not be left behind"
        );
    }
}

#[utopia_async::test]
async fn a_shared_collection_whose_indexes_are_not_created_is_kept() {
    for (name, create_indexes, indexes) in indexes_not_created() {
        assert!(
            dropped_after_a_failed_create(create_indexes, indexes, true, false).await.is_empty(),
            "{name}: Another tenant may already use a shared collection; the next create gives it its indexes"
        );
    }
}

#[utopia_async::test]
async fn a_collection_created_in_a_transaction_is_left_to_its_abort() {
    for (name, create_indexes, indexes) in indexes_not_created() {
        assert!(
            dropped_after_a_failed_create(create_indexes, indexes, false, true).await.is_empty(),
            "{name}: MongoDB runs no drop inside a transaction; the abort discards the collection"
        );
    }
}

// ---- MongoUniqueIndexWaitTest

async fn wait_adapter(list_indexes: impl Fn() -> DriverResult<Bson> + Send + Sync + 'static) -> Mongo {
    let fake = Fake::new();
    fake.on("query", move |_| list_indexes());
    let adapter = Mongo::with_driver(fake).await.unwrap();
    adapter.set_namespace("index_wait");
    adapter
}

fn timed_out() -> utopia_mongo::Error {
    utopia_mongo::Error::Engine { class: "RuntimeException", message: "the listing timed out".into() }
}

#[utopia_async::test]
async fn a_listing_that_fails_once_is_retried_until_the_index_is_ready() {
    let listings = Arc::new(AtomicI64::new(0));
    let count = listings.clone();
    let adapter = wait_adapter(move || {
        if count.fetch_add(1, Ordering::Relaxed) == 0 {
            return Err(timed_out());
        }
        Ok(Bson::Document(doc! { "cursor": { "firstBatch": [{ "name": "unique_email", "buildState": "ready" }] } }))
    })
    .await;

    let index = Index::unique("unique_email", ["email"], vec![], vec![]).unwrap();
    assert!(adapter.create_index("users", &index, &[], &[]).await.unwrap());
    assert_eq!(listings.load(Ordering::Relaxed), 2);
}

#[utopia_async::test]
async fn a_listing_that_keeps_failing_times_out_with_its_last_error() {
    let listings = Arc::new(AtomicI64::new(0));
    let count = listings.clone();
    let adapter = wait_adapter(move || {
        count.fetch_add(1, Ordering::Relaxed);
        Err(timed_out())
    })
    .await;

    let index = Index::unique("unique_email", ["email"], vec![], vec![]).unwrap();
    let thrown = adapter.create_index("users", &index, &[], &[]).await.unwrap_err();

    assert!(thrown.is_a(Kind::Database));
    assert_eq!(thrown.message(), "Timeout waiting for index creation: the listing timed out");
    assert_eq!(thrown.previous().map(Error::kind), Some(Kind::Runtime));
    assert_eq!(listings.load(Ordering::Relaxed), 10);
}

// ---- MongoDatabaseExistsTest

async fn listing_adapter(databases: &[&str]) -> Mongo {
    let fake = Fake::new();
    let names: Vec<Bson> = databases.iter().map(|n| Bson::Document(doc! { "name": *n })).collect();
    fake.on("listDatabaseNames", move |_| Ok(Bson::Document(doc! { "databases": names.clone() })));
    Mongo::with_driver(fake).await.unwrap()
}

#[utopia_async::test]
async fn a_listed_database_exists() {
    assert!(listing_adapter(&["admin", "library"]).await.exists("library").await.unwrap());
}

#[utopia_async::test]
async fn an_unlisted_database_does_not_exist() {
    assert!(!listing_adapter(&["admin", "library"]).await.exists("archive").await.unwrap());
}

#[utopia_async::test]
async fn no_database_exists_on_an_empty_server() {
    assert!(!listing_adapter(&[]).await.exists("library").await.unwrap());
}

#[utopia_async::test]
async fn the_name_is_filtered_before_lookup() {
    assert!(listing_adapter(&["library"]).await.exists("lib.rary").await.unwrap());
}

// ---- MongoDatabaseRenameTest

/// `MongoDatabaseRenameClient`: databases of collections, renamed by
/// `renameCollection`.
#[derive(Default)]
struct Server {
    databases: Mutex<BTreeMap<String, Vec<String>>>,
    fail_on: Mutex<Option<String>>,
    cursor: AtomicI64,
}

async fn rename_adapter(databases: &[(&str, &[&str])]) -> (Mongo, Arc<Server>) {
    let server = Arc::new(Server::default());
    *server.databases.lock().unwrap() =
        databases.iter().map(|(d, cs)| ((*d).to_owned(), cs.iter().map(|c| (*c).to_owned()).collect())).collect();
    let fake = Fake::new();
    let listed = server.clone();
    fake.on("listDatabaseNames", move |_| {
        let names: Vec<Bson> =
            listed.databases.lock().unwrap().keys().map(|n| Bson::Document(doc! { "name": n })).collect();
        Ok(Bson::Document(doc! { "databases": names }))
    });
    let created = server.clone();
    fake.on("createCollection", move |args| {
        created
            .databases
            .lock()
            .unwrap()
            .entry("default".into())
            .or_default()
            .push(args[0].as_str().unwrap().to_owned());
        Ok(Bson::Boolean(true))
    });
    let dropped = server.clone();
    fake.on("dropDatabase", move |args| {
        dropped.databases.lock().unwrap().remove(args[1].as_str().unwrap_or("default"));
        Ok(Bson::Boolean(true))
    });
    let queried = server.clone();
    fake.on("query", move |args| {
        let command = match &args[0] {
            Bson::Document(d) => d.clone(),
            _ => Default::default(),
        };
        let db = args[1].as_str().unwrap_or("default").to_owned();
        let mut databases = queried.databases.lock().unwrap();
        if let Ok(from) = command.get_str("renameCollection") {
            if queried.fail_on.lock().unwrap().as_deref() == Some(from) {
                return Err(error("rename refused", 0));
            }
            let to = command.get_str("to").unwrap();
            let (from_database, collection) = from.split_once('.').unwrap();
            let (to_database, _) = to.split_once('.').unwrap();
            let source = databases.get_mut(from_database).unwrap();
            source.retain(|c| c != collection);
            if source.is_empty() {
                databases.remove(from_database);
            }
            let target = databases.entry(to_database.to_owned()).or_default();
            target.push(collection.to_owned());
            target.sort();
            return Ok(Bson::Document(doc! {}));
        }
        if command.contains_key("listCollections") {
            let name = at(&Bson::Document(command.clone()), "filter.name").and_then(Bson::as_str).map(str::to_owned);
            let names: Vec<Bson> = databases
                .get(&db)
                .cloned()
                .unwrap_or_default()
                .into_iter()
                .filter(|c| name.as_ref().is_none_or(|n| n == c))
                .map(|c| Bson::Document(doc! { "name": c }))
                .collect();
            return Ok(Bson::Document(
                doc! { "cursor": { "id": queried.cursor.load(Ordering::Relaxed), "firstBatch": names } },
            ));
        }
        Ok(Bson::Document(doc! {}))
    });
    (Mongo::with_driver(fake).await.unwrap(), server)
}

fn databases(server: &Server) -> Vec<(String, Vec<String>)> {
    server.databases.lock().unwrap().iter().map(|(k, v)| (k.clone(), v.clone())).collect()
}

fn expected(databases: &[(&str, &[&str])]) -> Vec<(String, Vec<String>)> {
    databases.iter().map(|(d, cs)| ((*d).to_owned(), cs.iter().map(|c| (*c).to_owned()).collect())).collect()
}

#[utopia_async::test]
async fn rename_moves_every_collection_and_drops_the_old_database() {
    let (adapter, server) = rename_adapter(&[("library", &["authors", "books", "system.views"])]).await;

    assert!(adapter.update("library", "archive").await.unwrap());

    assert_eq!(databases(&server), expected(&[("archive", &["authors", "books"])]));
}

#[utopia_async::test]
async fn a_failure_part_way_moves_the_moved_collections_back() {
    let (adapter, server) = rename_adapter(&[("library", &["authors", "books", "loans"])]).await;
    *server.fail_on.lock().unwrap() = Some("library.loans".into());

    let thrown = adapter.update("library", "archive").await.unwrap_err();

    assert!(thrown.message().contains("rename refused"));
    assert_eq!(databases(&server), expected(&[("library", &["authors", "books", "loans"])]));
}

#[utopia_async::test]
async fn a_paged_listing_is_refused_before_anything_moves() {
    let (adapter, server) = rename_adapter(&[("library", &["authors", "books"])]).await;
    server.cursor.store(42, Ordering::Relaxed);

    let result = adapter.update("library", "archive").await;

    assert_error(
        result,
        Kind::Database,
        "Database has more collections than one listing returns, so it cannot be renamed",
    );
    assert_eq!(databases(&server), expected(&[("library", &["authors", "books"])]));
}

#[utopia_async::test]
async fn shared_tables_refuse_the_rename_before_anything_moves() {
    let (adapter, server) = rename_adapter(&[("library", &["books"])]).await;
    adapter.set_shared_tables(true);

    let result = adapter.update("library", "archive").await;

    assert_error(result, Kind::Database, "Cannot rename a database while shared tables are enabled");
    assert_eq!(databases(&server), expected(&[("library", &["books"])]));
}

#[utopia_async::test]
async fn collection_exists_asks_the_named_database() {
    let (adapter, _) = rename_adapter(&[("library", &["ns_books"]), ("archive", &[])]).await;
    adapter.set_namespace("ns");

    assert!(adapter.collection_exists("library", "books").await.unwrap());
    assert!(!adapter.collection_exists("archive", "books").await.unwrap());
}

#[utopia_async::test]
async fn a_shared_collection_already_in_the_adapters_database_is_not_created_again() {
    let (adapter, server) = rename_adapter(&[("library", &["ns_books"]), ("archive", &[])]).await;
    adapter.set_namespace("ns");
    adapter.set_database("library");
    adapter.set_shared_tables(true);

    let result = adapter.create_collection("books", &[], &[]).await;

    assert!(
        result.is_err_and(|e| e.is_a(Kind::Duplicate)),
        "A shared collection the adapter's database already has must be reported as existing"
    );
    assert_eq!(databases(&server), expected(&[("archive", &[]), ("library", &["ns_books"])]));
}

// ---- MongoNameFilterTest

async fn names_adapter() -> (Mongo, Arc<Fake>) {
    let fake = Fake::new();
    let collections: Arc<Mutex<Vec<String>>> = Arc::default();
    let created = collections.clone();
    fake.on("createCollection", move |args| {
        created.lock().unwrap().push(args[0].as_str().unwrap().to_owned());
        Ok(Bson::Boolean(true))
    });
    fake.on("query", move |args| {
        let name = at(&args[0], "filter.name").and_then(Bson::as_str).map(str::to_owned);
        let matches: Vec<Bson> = match name {
            Some(n) if collections.lock().unwrap().contains(&n) => vec![Bson::Document(doc! { "name": n })],
            _ => vec![],
        };
        Ok(Bson::Document(doc! { "cursor": { "firstBatch": matches, "id": 0 } }))
    });
    let adapter = crate::fake::adapter(&fake, "names", true).await;
    (adapter, fake)
}

#[utopia_async::test]
async fn collection_with_period_in_its_id_exists_after_creation() {
    let (adapter, _) = names_adapter().await;

    adapter.create_collection("audit.logs", &[], &[]).await.unwrap();

    assert!(adapter.collection_exists("app", "audit.logs").await.unwrap());
}

#[utopia_async::test]
async fn collection_with_nul_byte_in_its_id_exists_after_creation() {
    let (adapter, _) = names_adapter().await;

    adapter.create_collection("audit\0logs", &[], &[]).await.unwrap();

    assert!(adapter.collection_exists("app", "audit\0logs").await.unwrap());
}

#[utopia_async::test]
async fn collection_that_was_never_created_does_not_exist() {
    let (adapter, _) = names_adapter().await;

    adapter.create_collection("audit.logs", &[], &[]).await.unwrap();

    assert!(!adapter.collection_exists("app", "audit.events").await.unwrap());
}

#[utopia_async::test]
async fn delete_drops_the_database_that_set_database_selects() {
    let (adapter, fake) = names_adapter().await;
    adapter.set_database("tenant.data\0");

    assert!(adapter.delete("tenant.data\0").await.unwrap());

    let dropped: Vec<Bson> = fake.args("dropDatabase").into_iter().map(|a| a[1].clone()).collect();
    assert_eq!(dropped, vec![Bson::String(adapter.database())]);
    assert_eq!(dropped, vec![Bson::String("tenantdata".into())]);
}

#[utopia_async::test]
async fn delete_keeps_an_already_valid_database_name() {
    let (adapter, fake) = names_adapter().await;

    adapter.delete("tenant_data-1").await.unwrap();

    let dropped: Vec<Bson> = fake.args("dropDatabase").into_iter().map(|a| a[1].clone()).collect();
    assert_eq!(dropped, vec![Bson::String("tenant_data-1".into())]);
}
