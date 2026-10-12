//! The benchmarks of `database`: the twins of `benchmarks()` in
//! `tests/compat/database/Adapter.php`, on the same collection, document
//! and queries.

use std::hint::black_box;
use std::rc::Rc;
use std::sync::Arc;

use utopia_cache::{AnyCodec, Cache, Redis};
use utopia_database::adapter::Adapter;
use utopia_database::adapter::postgres::Postgres;
use utopia_database::adapter::redis::Redis as RedisAdapter;
use utopia_database::helpers::id;
use utopia_database::validator::queries::documents::Documents as DocumentsQueries;
use utopia_database::validator::structure::Structure;
use utopia_database::value::Str;
use utopia_database::{Authorization, Database, Document, Query, Value};

use crate::adapter::{Fault, Session};
use crate::bench::Bench;

/// The benchmarks' collection: a user profile, as Appwrite stores one
/// (`Adapter::ATTRIBUTES`).
const ATTRIBUTES: &str = r#"[
    {"$id": "name", "key": "name", "type": "string", "size": 128, "required": true, "signed": true, "array": false, "filters": []},
    {"$id": "email", "key": "email", "type": "string", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
    {"$id": "age", "key": "age", "type": "integer", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
    {"$id": "score", "key": "score", "type": "double", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
    {"$id": "active", "key": "active", "type": "boolean", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
    {"$id": "tags", "key": "tags", "type": "string", "size": 64, "required": false, "signed": true, "array": true, "filters": []},
    {"$id": "born", "key": "born", "type": "datetime", "size": 0, "required": false, "signed": false, "array": false, "filters": ["datetime"]}
]"#;

/// `Adapter::INDEXES`.
const INDEXES: &str =
    r#"[{"$id": "by_name", "key": "by_name", "type": "key", "attributes": ["name"], "lengths": [], "orders": []}]"#;

/// A user document of the collection (`Adapter::DOCUMENT`).
const DOCUMENT: &str = r#"{"$id": "alice", "$collection": "users", "$permissions": ["read(\"any\")", "update(\"any\")"],
    "$createdAt": "2024-01-01T00:00:00.000+00:00", "$updatedAt": "2024-01-01T00:00:00.000+00:00",
    "name": "Alice", "email": "alice@example.com", "age": 31, "score": 4.5, "active": true,
    "tags": ["admin", "beta", "eu"], "born": "1990-05-17T10:00:00.000+00:00"}"#;

/// A list request's queries, as the HTTP layer receives them
/// (`Adapter::QUERIES`).
const QUERIES: [&str; 5] = [
    r#"{"method":"equal","attribute":"name","values":["Alice","Bob"]}"#,
    r#"{"method":"greaterThan","attribute":"age","values":[18]}"#,
    r#"{"method":"orderDesc","attribute":"score","values":[]}"#,
    r#"{"method":"limit","values":[25]}"#,
    r#"{"method":"select","values":["name","email","age"]}"#,
];

pub const BENCHES: &[(&str, &str)] = &[
    ("query_parse", "Parse a list request's five queries from their JSON strings"),
    (
        "queries_validate",
        "Validate five parsed queries against a collection's attributes and indexes (Queries\\Documents)",
    ),
    ("structure_validate", "Validate a user document against its collection's structure"),
    ("document_encode", "Encode a user document for the adapter (defaults, the datetime filter)"),
    ("document_decode", "Decode a stored user document (filters reversed, internal keys mapped back)"),
    ("create_document", "Create a user document: validation, encoding and one INSERT round trip"),
    ("create_documents", "Create ten user documents in one batch (one INSERT round trip)"),
    ("get_document", "Read a user document by id, uncached (one SELECT round trip)"),
    ("find", "Find users by an indexed name, ordered and limited (one SELECT round trip over 100 rows)"),
    ("update_document", "Update one attribute of a user document (a locking read and one UPDATE)"),
    (
        "cached_get_document",
        "Read a user document by id from a Redis cache (Json entries): one HGET round trip and the decode",
    ),
    (
        "redis_get_document",
        "Read a user document by id on the Redis adapter, uncached (a GET and its permissions in one MULTI)",
    ),
    (
        "redis_find",
        "Find users on the Redis adapter: load 100 documents and filter, order and limit them in the process",
    ),
];

fn fault(e: impl std::fmt::Display) -> Fault {
    Fault::new(e.to_string())
}

/// `json_decode($json, true)` as a value.
fn json(text: &str) -> Result<Value, Fault> {
    let decoded = php_std::json::decode(text.as_bytes(), Some(true), 512, php_std::json::Flags::NONE).map_err(fault)?;
    Ok(utopia_database::value::from_plain(decoded))
}

/// Each element of a JSON list as a document.
fn documents(text: &str) -> Result<Vec<Document>, Fault> {
    match json(text)? {
        Value::Array(list) => list
            .values()
            .map(|v| match v {
                Value::Array(a) => Document::new(a.clone()).map_err(fault),
                _ => Err(fault("not an object")),
            })
            .collect(),
        _ => Err(fault("not a list")),
    }
}

fn document() -> Result<Document, Fault> {
    match json(DOCUMENT)? {
        Value::Array(a) => Document::new(a).map_err(fault),
        _ => Err(fault("not an object")),
    }
}

fn collection() -> Result<Document, Fault> {
    let mut c = Document::default();
    c.set("$id", Value::from("users"));
    c.set("$collection", Value::from(Database::METADATA));
    c.set("attributes", Value::Array(documents(ATTRIBUTES)?.into_iter().map(Value::from).collect()));
    c.set("indexes", Value::Array(documents(INDEXES)?.into_iter().map(Value::from).collect()));
    Ok(c)
}

/// A database of the benchmarks' collection on the compat PostgreSQL,
/// uncached, in a schema of its own (dropped first when a run left it), and
/// the collection's metadata document.
async fn database(session: &mut Session, name: &str) -> Result<(Database, Document), Fault> {
    let url = session.service("database-postgres").map_err(fault)?.to_owned();
    open(&url, name).await
}

/// [`database`] on the server at `url`.
async fn open(url: &str, name: &str) -> Result<(Database, Document), Fault> {
    let postgres = Postgres::connect(url).await.map_err(fault)?;
    on(Arc::new(postgres), uncached(), name).await
}

fn uncached() -> Arc<Cache> {
    Arc::new(Cache::from_dyn(Arc::new(utopia_cache::None)))
}

/// A connection to a Redis service of `compat.json`, on its database.
async fn redis(session: &mut Session, service: &str) -> Result<redis::aio::ConnectionManager, Fault> {
    let url = session.service(service).map_err(fault)?.to_owned();
    redis::Client::open(url.as_str()).map_err(fault)?.get_connection_manager().await.map_err(fault)
}

/// The benchmarks' database on the Redis adapter.
async fn on_redis(session: &mut Session, name: &str) -> Result<(Database, Document), Fault> {
    let adapter = RedisAdapter::new(redis(session, "database-redis-adapter").await?);
    on(Arc::new(adapter), uncached(), name).await
}

/// The benchmarks' database and collection on `adapter` and `cache`.
async fn on(adapter: Arc<dyn Adapter>, cache: Arc<Cache>, name: &str) -> Result<(Database, Document), Fault> {
    let db = Database::new(adapter, cache, Vec::new());
    let authorization = Authorization::new();
    authorization.add_role("any");
    db.set_authorization(Arc::new(authorization));
    db.set_database(&format!("compat_bench_rust_{name}")).map_err(fault)?;
    db.set_namespace("bench").map_err(fault)?;
    if db.exists(None, None).await.map_err(fault)? {
        db.delete(None).await.map_err(fault)?;
    }
    db.create(None).await.map_err(fault)?;
    let permissions = vec!["read(\"any\")".to_owned(), "create(\"any\")".to_owned(), "update(\"any\")".to_owned()];
    let collection = db
        .create_collection("users", documents(ATTRIBUTES)?, documents(INDEXES)?, Some(permissions), false)
        .await
        .map_err(fault)?;
    Ok((db, collection))
}

/// `$doc` with a fresh `ID::unique()`.
fn fresh(doc: &Document) -> Document {
    let mut doc = doc.clone();
    doc.set("$id", Value::Str(Str::from(id::unique().unwrap_or_default())));
    doc
}

/// The 100 users `find` runs over: `u1`..`u100`, named Alice and Bob in
/// turn, aged by their number.
fn rows(doc: &Document) -> Vec<Document> {
    (1..=100)
        .map(|i: i64| {
            let mut row = doc.clone();
            row.set("$id", Value::Str(Str::from(format!("u{i}"))));
            row.set("name", Value::from(if i % 2 == 1 { "Alice" } else { "Bob" }));
            row.set("age", Value::Int(i));
            row
        })
        .collect()
}

/// Times reading `alice` from `db`.
fn get(db: Database) -> Bench {
    let db = Rc::new(db);
    Bench::future(move || {
        let db = db.clone();
        async move {
            black_box(db.get_document("users", "alice", Vec::new(), false).await.ok());
        }
    })
}

pub async fn bench(name: &str, session: &mut Session) -> Result<Bench, Fault> {
    Ok(match name {
        "query_parse" => Bench::sync(|| {
            black_box(Query::parse_queries(black_box(&QUERIES)).ok());
        }),
        "queries_validate" => {
            let validator = DocumentsQueries::new(&documents(ATTRIBUTES)?, &documents(INDEXES)?, Database::VAR_INTEGER);
            let queries = Query::parse_queries(&QUERIES).map_err(fault)?;
            Bench::sync(move || {
                black_box(validator.check(black_box(&queries)));
            })
        }
        "structure_validate" => {
            let validator = Structure::new(collection()?, Database::VAR_INTEGER);
            let doc = Value::from(document()?);
            Bench::sync(move || {
                black_box(validator.is_valid(black_box(&doc)).ok());
            })
        }
        "document_encode" => {
            let (db, c) = database(session, name).await?;
            let state = Rc::new((db, c, document()?));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.encode(&state.1, state.2.clone(), true).await.ok());
                }
            })
        }
        "document_decode" => {
            let (db, c) = database(session, name).await?;
            let stored = db.encode(&c, document()?, true).await.map_err(fault)?;
            let state = Rc::new((db, c, stored));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.decode(&state.1, state.2.clone(), &[]).await.ok());
                }
            })
        }
        "create_document" => {
            let (db, _) = database(session, name).await?;
            let state = Rc::new((db, document()?));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.create_document("users", fresh(&state.1)).await.ok());
                }
            })
        }
        "create_documents" => {
            let (db, _) = database(session, name).await?;
            let state = Rc::new((db, document()?));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    let batch = (0..10).map(|_| fresh(&state.1)).collect();
                    black_box(
                        state.0.create_documents("users", batch, Database::INSERT_BATCH_SIZE, None, None).await.ok(),
                    );
                }
            })
        }
        "get_document" => {
            let (db, _) = database(session, name).await?;
            db.create_document("users", document()?).await.map_err(fault)?;
            get(db)
        }
        "find" => {
            let (db, _) = database(session, name).await?;
            db.create_documents("users", rows(&document()?), Database::INSERT_BATCH_SIZE, None, None)
                .await
                .map_err(fault)?;
            let queries = Query::parse_queries(&QUERIES).map_err(fault)?;
            let state = Rc::new((db, queries));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.find("users", state.1.clone(), Database::PERMISSION_READ).await.ok());
                }
            })
        }
        "update_document" => {
            let (db, _) = database(session, name).await?;
            db.create_document("users", document()?).await.map_err(fault)?;
            let state = Rc::new((db, std::cell::Cell::new(0i64)));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    let age = state.1.get() + 1;
                    state.1.set(age);
                    let mut changes = Document::default();
                    changes.set("age", Value::Int(age));
                    black_box(state.0.update_document("users", "alice", changes).await.ok());
                }
            })
        }
        "cached_get_document" => {
            let url = session.service("database-postgres").map_err(fault)?.to_owned();
            let postgres = Postgres::connect(&url).await.map_err(fault)?;
            let cache = Redis::with_codec(redis(session, "database-redis").await?, AnyCodec::Json);
            let (db, _) = on(Arc::new(postgres), Arc::new(Cache::from_dyn(Arc::new(cache))), name).await?;
            db.create_document("users", document()?).await.map_err(fault)?;
            db.get_document("users", "alice", Vec::new(), false).await.map_err(fault)?;
            get(db)
        }
        "redis_get_document" => {
            let (db, _) = on_redis(session, name).await?;
            db.create_document("users", document()?).await.map_err(fault)?;
            get(db)
        }
        "redis_find" => {
            let (db, _) = on_redis(session, name).await?;
            db.create_documents("users", rows(&document()?), Database::INSERT_BATCH_SIZE, None, None)
                .await
                .map_err(fault)?;
            let queries = Query::parse_queries(&QUERIES).map_err(fault)?;
            let state = Rc::new((db, queries));
            Bench::future(move || {
                let state = state.clone();
                async move {
                    black_box(state.0.find("users", state.1.clone(), Database::PERMISSION_READ).await.ok());
                }
            })
        }
        _ => return Err(Fault::new(format!("database: no benchmark `{name}`"))),
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The CPU benchmarks time the valid path, as PHP's do.
    #[test]
    fn fixtures_are_valid() {
        let structure = Structure::new(collection().unwrap(), Database::VAR_INTEGER);
        assert_eq!(
            structure.is_valid(&Value::from(document().unwrap())).ok(),
            Some(true),
            "{}",
            structure.description()
        );
        let queries = Query::parse_queries(&QUERIES).unwrap();
        let validator =
            DocumentsQueries::new(&documents(ATTRIBUTES).unwrap(), &documents(INDEXES).unwrap(), Database::VAR_INTEGER);
        assert!(validator.check(&queries), "{}", validator.description());
    }
}
