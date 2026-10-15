//! The SQL adapters' limits.
//!
//! PHP: `tests/unit/Adapter/LimitsTest.php` (the SQL adapters' cases; the
//! Mongo, Memory, Pool and `Database` cases belong to those ports).

#![cfg(feature = "sqlite")]

mod limits_test {
    use utopia_database::adapter::Adapter;
    use utopia_database::adapter::sql::pdo::Pdo;
    use utopia_database::adapter::sql::{Engine, Sql};
    use utopia_database::{database, storage};
    use utopia_query::schema::ColumnType;

    /// An adapter of `engine`; its limits never reach the connection, so an
    /// in-memory SQLite handle stands in for the server (PHP passes a
    /// `stdClass`).
    async fn adapter(engine: Engine) -> Sql {
        Sql::new(engine, Pdo::connect("sqlite::memory:", None, None).await.unwrap())
    }

    const ENGINES: [(Engine, &str); 4] = [
        (Engine::MariaDb, "1000-01-01 00:00:00"),
        (Engine::MySql, "1000-01-01 00:00:00"),
        (Engine::Postgres, "-4713-01-01 00:00:00"),
        (Engine::Sqlite, "1000-01-01 00:00:00"),
    ];

    #[tokio::test]
    async fn test_sql_adapters_share_inno_db_limits() {
        for (engine, min_date_time) in ENGINES {
            let limits = adapter(engine).await.limits();
            assert_eq!(limits.string, 4294967295, "{engine:?}");
            assert_eq!(limits.varchar, 16381);
            assert_eq!(limits.integer, 4294967295);
            assert_eq!(limits.big_integer, database::MAX_BIG_INT);
            assert_eq!(limits.attributes, 1017);
            assert_eq!(limits.indexes, 64);
            assert_eq!(limits.default_attributes, database::internal_attributes(true).len() as i64);
            assert_eq!(limits.default_indexes, database::INTERNAL_INDEXES.len() as i64);
            assert_eq!(limits.index_length, 768);
            assert_eq!(limits.uid_length, 36);
            assert_eq!(limits.document_size, 65535);
            assert_eq!(limits.min_date_time.format(b"Y-m-d H:i:s"), min_date_time.as_bytes(), "{engine:?}");
            assert_eq!(limits.max_date_time.format(b"Y-m-d H:i:s"), b"9999-12-31 23:59:59");
            assert_eq!(limits.id_type, ColumnType::Integer);
            assert_eq!(
                limits.internal_index_keys,
                [storage::INDEX_PRIMARY, storage::INDEX_CREATED_AT, storage::INDEX_UPDATED_AT, storage::INDEX_TENANT_ID]
            );
        }
    }

    #[tokio::test]
    async fn test_a_shared_table_spends_a_byte_of_each_index_key_on_the_tenant() {
        for (engine, _) in ENGINES {
            let adapter = adapter(engine).await;
            adapter.set_shared_tables(true);
            assert_eq!(adapter.limits().index_length, 767, "{engine:?}");
            adapter.set_shared_tables(false);
            assert_eq!(adapter.limits().index_length, 768, "{engine:?}");
        }
    }

    #[tokio::test]
    async fn test_setting_shared_tables_to_their_current_value_keeps_the_limits() {
        let adapter = adapter(Engine::MariaDb).await;
        let limits = adapter.limits();
        adapter.set_shared_tables(false);
        assert!(std::sync::Arc::ptr_eq(&limits, &adapter.limits()), "An unchanged mode must not rebuild the limits");
        adapter.set_shared_tables(true);
        assert_eq!(adapter.limits().index_length, limits.index_length - 1);
    }

    #[tokio::test]
    async fn test_sqlite_reserves_its_own_keywords() {
        let mariadb = adapter(Engine::MariaDb).await.limits().keywords.clone();
        let sqlite = adapter(Engine::Sqlite).await.limits().keywords.clone();
        assert!(mariadb.iter().any(|k| k == "SELECT"));
        assert!(!mariadb.iter().any(|k| k == "ABORT"));
        assert!(sqlite.iter().any(|k| k == "ABORT"));
    }
}
