//! The benchmarks of `database`: the twins of `benchmarks()` in
//! `tests/compat/database/Adapter.php`, on the same collection, document
//! and queries.

use crate::adapter::{Fault, Session};
use crate::bench::Bench;

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
    ("sqlite_create_document", "Create a user document on SQLite (a file in the runtime's container): one INSERT"),
    ("sqlite_get_document", "Read a user document by id on SQLite, uncached (one SELECT)"),
    ("sqlite_find", "Find users by an indexed name on SQLite, ordered and limited (one SELECT over 100 rows)"),
    ("sqlite_update_document", "Update one attribute of a user document on SQLite (a read and one UPDATE)"),
    ("memory_create_document", "Create a user document on the in-memory adapter"),
    ("memory_get_document", "Read a user document by id on the in-memory adapter, uncached"),
    ("memory_find", "Find users by name on the in-memory adapter, ordered and limited (a scan of 100 documents)"),
    ("memory_update_document", "Update one attribute of a user document on the in-memory adapter"),
    ("create_document_mariadb", "Create a user document: validation, encoding and one INSERT round trip on MariaDB"),
    ("create_documents_mariadb", "Create ten user documents in one batch (one INSERT round trip) on MariaDB"),
    ("get_document_mariadb", "Read a user document by id, uncached (one SELECT round trip) on MariaDB"),
    (
        "find_mariadb",
        "Find users by an indexed name, ordered and limited (one SELECT round trip over 100 rows) on MariaDB",
    ),
    ("update_document_mariadb", "Update one attribute of a user document (a locking read and one UPDATE) on MariaDB"),
    ("create_document_mysql", "Create a user document: validation, encoding and one INSERT round trip on MySQL"),
    ("create_documents_mysql", "Create ten user documents in one batch (one INSERT round trip) on MySQL"),
    ("get_document_mysql", "Read a user document by id, uncached (one SELECT round trip) on MySQL"),
    ("find_mysql", "Find users by an indexed name, ordered and limited (one SELECT round trip over 100 rows) on MySQL"),
    ("update_document_mysql", "Update one attribute of a user document (a locking read and one UPDATE) on MySQL"),
    ("mongo_create_document", "Create a user document on MongoDB: validation, encoding and one insert round trip"),
    ("mongo_get_document", "Read a user document by id on MongoDB, uncached (one find round trip)"),
    (
        "mongo_find",
        "Find users by an indexed name on MongoDB, ordered and limited (one find round trip over 100 documents)",
    ),
    ("mongo_update_document", "Update one attribute of a user document on MongoDB (a read and one update)"),
];

/// Runs one benchmark.
pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Err(Fault::new(format!("database: benchmark `{name}` is not ported to the database 8 crate yet")))
}
