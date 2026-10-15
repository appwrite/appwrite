//! SQLite's transaction statements are not profiled: PHP's
//! `SQLite::startTransaction()`, `commitTransaction()` and
//! `rollbackTransaction()` run `$this->prepare($sql)->execute()`, the
//! statement itself, not `execute()`, which hands statements to the
//! profiler.

#![cfg(feature = "sqlite")]

use std::sync::Arc;

use utopia_database::adapter::Adapter;
use utopia_database::adapter::sql::{Engine, Sql};
use utopia_database::profiler::Profiler;

#[tokio::test]
async fn transaction_statements_are_not_profiled() {
    let adapter = Sql::connect(Engine::Sqlite, "sqlite::memory:", None, None).await.unwrap();
    let profiler = Arc::new(Profiler::new());
    profiler.enable();
    adapter.set_profiler(Some(profiler.clone()));

    assert!(adapter.start_transaction().await.unwrap());
    assert!(adapter.start_transaction().await.unwrap());
    assert!(adapter.commit_transaction().await.unwrap());
    assert!(adapter.rollback_transaction().await.unwrap());
    assert!(adapter.start_transaction().await.unwrap());
    assert!(adapter.commit_transaction().await.unwrap());

    assert_eq!(profiler.query_count(), 0, "{:?}", profiler.logs().iter().map(|l| &l.query).collect::<Vec<_>>());
}
