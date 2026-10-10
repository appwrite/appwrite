//! Rust adapters, one module per library (`tests/compat/<lib>`).
//!
//! Each module exposes `OPS` (the operations it implements) and
//! `call(op, args, session)`. Add a library by adding its module here and to
//! [`LIBS`] and [`call`].

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub mod auth;
pub mod cache;
pub mod database;
pub mod dsn;
pub mod emails;
pub mod http;
pub mod locale;
pub mod lock;
pub mod php_std;
pub mod query;
pub mod queue;
pub mod system;
pub mod user_agent;
pub mod validators;

/// Library ids (the `tests/compat/<lib>` directory names) and their operations.
pub const LIBS: &[(&str, &[&str])] = &[
    ("auth", auth::OPS),
    ("cache", cache::OPS),
    ("database", database::OPS),
    ("dsn", dsn::OPS),
    ("emails", emails::OPS),
    ("http", http::OPS),
    ("locale", locale::OPS),
    ("lock", lock::OPS),
    ("php-std", php_std::OPS),
    ("query", query::OPS),
    ("queue", queue::OPS),
    ("system", system::OPS),
    ("user-agent", user_agent::OPS),
    ("validators", validators::OPS),
];

/// Runs one operation of one library.
pub async fn call(lib: &str, op: &str, args: &Value, session: &mut Session) -> OpResult {
    match lib {
        "auth" => auth::call(op, args, session).await,
        "cache" => cache::call(op, args, session).await,
        "database" => database::call(op, args, session).await,
        "dsn" => dsn::call(op, args, session).await,
        "emails" => emails::call(op, args, session).await,
        "http" => http::call(op, args, session).await,
        "locale" => locale::call(op, args, session).await,
        "lock" => lock::call(op, args, session).await,
        "php-std" => php_std::call(op, args, session).await,
        "query" => query::call(op, args, session).await,
        "queue" => queue::call(op, args, session).await,
        "system" => system::call(op, args, session).await,
        "user-agent" => user_agent::call(op, args, session).await,
        "validators" => validators::call(op, args, session).await,
        _ => Err(Fault::new(format!("unknown library `{lib}`"))),
    }
}
