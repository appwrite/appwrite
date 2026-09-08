//! Live adapter tests against SQLite, MySQL, MariaDB, Postgres, and Mongo.
//!
//! SQLite runs with default features (file DB, no container). SQL/Mongo engines
//! connect to the compose stack from `docker-compose.test.yml` (same ports as
//! utopia-php/database).

use serde_json::json;
use utopia_cache::adapter::Memory as MemoryCache;
use utopia_cache::Cache;
use utopia_database::helpers::{Id, Permission, Role};
use utopia_database::prelude::*;
use utopia_database::query::Query;
use utopia_database::{AttrValue, PERMISSION_READ};

#[cfg(any(feature = "mysql", feature = "postgres", feature = "mongo"))]
fn env_host(name: &str, default: &str) -> String {
    std::env::var(name)
        .ok()
        .filter(|s| !s.is_empty())
        .unwrap_or_else(|| default.to_owned())
}

#[cfg(any(feature = "mysql", feature = "postgres"))]
fn env_port(name: &str, default: u16) -> u16 {
    std::env::var(name)
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(default)
}

fn crud_on<A: Adapter>(adapter: A) {
    let cache = Cache::new(MemoryCache::new());
    let mut db = Database::new(adapter, cache);
    db.set_database("utopiaTests").unwrap();
    db.set_namespace(&format!("ns_{}", Id::unique().unwrap()))
        .unwrap();
    if db.exists(None, None).unwrap() {
        db.delete(None).unwrap();
    }
    db.create(None).unwrap();
    assert!(db.ping());

    db.skip_authorization(|db| {
        db.create_collection(
            "movies",
            vec![Document::from_pairs([
                ("$id", AttrValue::from("title")),
                ("type", AttrValue::from("string")),
                ("size", AttrValue::from(128i64)),
                ("required", AttrValue::from(true)),
                ("signed", AttrValue::from(true)),
                ("array", AttrValue::from(false)),
            ])
            .unwrap()],
            vec![],
            Some(vec![
                Permission::create(&Role::any()),
                Permission::read(&Role::any()),
                Permission::update(&Role::any()),
                Permission::delete(&Role::any()),
            ]),
            true,
        )
    })
    .unwrap();

    let doc = db
        .skip_authorization(|db| {
            db.create_document(
                "movies",
                Document::from_pairs([
                    ("$id", AttrValue::from("tt1")),
                    ("title", AttrValue::from("Dune")),
                ])
                .unwrap(),
            )
        })
        .unwrap();
    assert_eq!(doc.get_id(), "tt1");
    assert!(doc.get_sequence().is_some());

    let found = db
        .skip_authorization(|db| db.get_document("movies", "tt1", &[], false))
        .unwrap();
    assert_eq!(found.get_attribute("title").as_str(), Some("Dune"));

    let listed = db
        .skip_authorization(|db| {
            db.find(
                "movies",
                &[Query::equal("title", vec![AttrValue::from("Dune")])],
                PERMISSION_READ,
            )
        })
        .unwrap();
    assert_eq!(listed.len(), 1);

    let count = db
        .skip_authorization(|db| db.count("movies", &[], None))
        .unwrap();
    assert_eq!(count, 1);

    db.skip_authorization(|db| {
        db.update_document(
            "movies",
            "tt1",
            Document::from_pairs([
                ("$id", AttrValue::from("tt1")),
                ("title", AttrValue::from("Dune: Part Two")),
            ])
            .unwrap(),
        )
    })
    .unwrap();
    let updated = db
        .skip_authorization(|db| db.get_document("movies", "tt1", &[], false))
        .unwrap();
    assert_eq!(
        updated.get_attribute("title").as_str(),
        Some("Dune: Part Two")
    );

    db.skip_authorization(|db| db.delete_document("movies", "tt1"))
        .unwrap();
    let missing = db
        .skip_authorization(|db| db.get_document("movies", "tt1", &[], false))
        .unwrap();
    assert!(missing.is_empty());

    db.delete(None).unwrap();
}

#[cfg(feature = "sqlite")]
#[test]
fn sqlite_adapter_crud() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("utopia.db");
    let adapter = utopia_database::adapter::sqlite::Sqlite::new(path.to_str().unwrap()).unwrap();
    crud_on(adapter);
}

#[cfg(feature = "mysql")]
#[test]
fn mysql_adapter_crud() {
    let adapter = utopia_database::adapter::mysql::Mysql::connect(
        &env_host("MYSQL_HOST", "127.0.0.1"),
        env_port("MYSQL_PORT", 8706),
        &env_host("MYSQL_USER", "root"),
        &env_host("MYSQL_PASSWORD", "password"),
    )
    .expect("MySQL container (docker compose -f docker-compose.test.yml up -d mysql)");
    crud_on(adapter);
}

#[cfg(feature = "mysql")]
#[test]
fn mariadb_adapter_crud() {
    let adapter = utopia_database::adapter::mysql::MariaDb::connect(
        &env_host("MARIADB_HOST", "127.0.0.1"),
        env_port("MARIADB_PORT", 8703),
        &env_host("MARIADB_USER", "root"),
        &env_host("MARIADB_PASSWORD", "password"),
    )
    .expect("MariaDB container (docker compose -f docker-compose.test.yml up -d mariadb)");
    crud_on(adapter);
}

#[cfg(feature = "postgres")]
#[test]
fn postgres_adapter_crud() {
    let adapter = utopia_database::adapter::postgres::Postgres::connect(
        &env_host("POSTGRES_HOST", "127.0.0.1"),
        env_port("POSTGRES_PORT", 8701),
        &env_host("POSTGRES_USER", "root"),
        &env_host("POSTGRES_PASSWORD", "password"),
        &env_host("POSTGRES_DB", "root"),
    )
    .expect("Postgres container (docker compose -f docker-compose.test.yml up -d postgres)");
    crud_on(adapter);
}

#[cfg(feature = "mongo")]
#[test]
fn mongo_adapter_crud() {
    let uri = env_host(
        "MONGO_URI",
        "mongodb://root:password@127.0.0.1:27018/?authSource=admin&directConnection=true",
    );
    let adapter = utopia_database::adapter::mongo::Mongo::connect(&uri)
        .expect("MongoDB container (docker compose -f docker-compose.test.yml up -d mongo)");
    crud_on(adapter);
}

#[test]
fn memory_adapter_still_covered() {
    let _ = json!(null);
    crud_on(Memory::new());
}
