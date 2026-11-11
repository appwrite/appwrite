//! The SQL adapters' shared base: connection, transactions, statements.

#![cfg(feature = "sqlite")]

use std::sync::{Arc, Mutex};

use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::adapter::{Adapter, with_transaction};
use utopia_database::event::Event;
use utopia_database::hook::Transform;

async fn sqlite() -> Sql {
    let adapter = Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await.unwrap();
    adapter.set_database("db");
    adapter.set_namespace("ns");
    adapter
}

struct Recorder(Arc<Mutex<Vec<(Event, String)>>>);

impl Transform for Recorder {
    fn transform(&self, event: Event, query: String) -> utopia_database::Result<String> {
        self.0.lock().unwrap().push((event, query.clone()));
        Ok(query)
    }
}

#[utopia_async::test]
async fn sqlite_connection() {
    let adapter = sqlite().await;
    assert!(utopia_database::adapter::Connection::ping(&adapter).await.unwrap());
    assert!(adapter.create("db").await.unwrap());
    assert!(!adapter.exists("db").await.unwrap());
    assert!(!adapter.collection_exists("db", "users").await.unwrap());
    assert_eq!(utopia_database::adapter::Connection::hostname(&adapter).await.unwrap(), "");
}

#[utopia_async::test]
async fn sqlite_transactions_nest_as_savepoints() {
    let adapter = sqlite().await;
    let statements = Arc::new(Mutex::new(Vec::new()));
    adapter.add_transform("rec", Arc::new(Recorder(statements.clone())));
    adapter.set_metadata("request", "abc".into());
    let value =
        with_transaction(&adapter, || async { with_transaction(&adapter, || async { Ok(7) }).await }).await.unwrap();
    assert_eq!(value, 7);
    assert!(!adapter.in_transaction());
    assert!(!adapter.commit_transaction().await.unwrap());
    // Transaction statements carry no event, so transforms do not see them.
    assert!(statements.lock().unwrap().is_empty());
}

#[utopia_async::test]
async fn statements_carry_metadata_comments() {
    let adapter = sqlite().await;
    let statements = Arc::new(Mutex::new(Vec::new()));
    adapter.add_transform("rec", Arc::new(Recorder(statements.clone())));
    adapter.set_metadata("request", "a*/b".into());
    adapter.collection_exists("db", "users").await.unwrap();
    let recorded = statements.lock().unwrap().clone();
    assert_eq!(recorded.len(), 1);
    assert_eq!(recorded[0].0, Event::CollectionRead);
    assert_eq!(
        recorded[0].1,
        "/* request: a* /b */\n\n\t\t\tSELECT name FROM sqlite_master\n\t\t\tWHERE type='table' AND name = :table\n\t\t"
    );
}

/// The compat servers: (engine, DSN).
const SERVERS: [(Engine, &str); 3] = [
    (Engine::Postgres, "pgsql:host=localhost;port=16432;dbname=root"),
    (Engine::MariaDb, "mysql:host=localhost;port=16306;charset=utf8mb4"),
    (Engine::MySql, "mysql:host=localhost;port=16307;charset=utf8mb4"),
];

async fn server(engine: Engine, dsn: &str) -> Option<Sql> {
    match Sql::connect(engine, dsn, Some("root"), Some("password")).await {
        Ok(adapter) => Some(adapter),
        Err(e) => {
            eprintln!("skipping {engine:?}: {}", e.message());
            None
        }
    }
}

#[utopia_async::test]
async fn servers_create_rename_and_delete_databases() {
    for (engine, dsn) in SERVERS {
        let Some(adapter) = server(engine, dsn).await else { continue };
        let name = format!("l3a_db_{}", std::process::id());
        let renamed = format!("{name}_r");
        let _ = adapter.delete(&name).await;
        let _ = adapter.delete(&renamed).await;
        assert!(adapter.create(&name).await.unwrap(), "{engine:?}");
        assert!(adapter.exists(&name).await.unwrap(), "{engine:?}");
        assert!(adapter.create(&name).await.unwrap(), "{engine:?} creating twice");
        assert!(adapter.update(&name, &renamed).await.unwrap(), "{engine:?}");
        assert!(!adapter.exists(&name).await.unwrap(), "{engine:?}");
        assert!(adapter.exists(&renamed).await.unwrap(), "{engine:?}");
        let missing = adapter.update(&name, &renamed).await.unwrap_err();
        assert_eq!(missing.message(), "Database not found");
        assert!(adapter.delete(&renamed).await.unwrap(), "{engine:?}");
        assert!(!utopia_database::adapter::Connection::id(&adapter).await.unwrap().is_empty());
        assert_eq!(utopia_database::adapter::Connection::hostname(&adapter).await.unwrap(), "localhost");
    }
}

#[utopia_async::test]
async fn servers_nest_transactions() {
    for (engine, dsn) in SERVERS {
        let Some(adapter) = server(engine, dsn).await else { continue };
        let value = with_transaction(&adapter, || async {
            assert!(adapter.in_transaction());
            let inner: utopia_database::Result<i64> =
                with_transaction(&adapter, || async { Err(utopia_database::Error::query("inner")) }).await;
            assert_eq!(inner.unwrap_err().message(), "inner");
            Ok(3)
        })
        .await
        .unwrap();
        assert_eq!(value, 3, "{engine:?}");
        assert!(!adapter.in_transaction());
        assert!(!adapter.pdo().lock().await.in_transaction(), "{engine:?}");
    }
}
