//! Rust adapters, one module per library (`tests/compat/<lib>`).
//!
//! Each module exposes `OPS` (the operations it implements) and
//! `call(op, args, session)`. Add a library by adding its module here and to
//! [`LIBS`] and [`call`]. A module with benchmarks also exposes `BENCHES`
//! (name and description of each) and `bench(name, session)`, listed in
//! [`benches`] and [`bench`].

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};
use crate::bench::Bench;

pub mod auth;
pub mod cache;
pub mod database;
pub mod di;
pub mod domains;
pub mod dsn;
pub mod emails;
pub mod http;
pub mod locale;
pub mod lock;
pub mod php_std;
pub mod query;
pub mod queue;
pub mod registry;
pub mod span;
pub mod system;
pub mod user_agent;
pub mod validators;

/// Library ids (the `tests/compat/<lib>` directory names) and their operations.
pub const LIBS: &[(&str, &[&str])] = &[
    ("auth", auth::OPS),
    ("cache", cache::OPS),
    ("database", database::OPS),
    ("domains", domains::OPS),
    ("dsn", dsn::OPS),
    ("emails", emails::OPS),
    ("http", http::OPS),
    ("locale", locale::OPS),
    ("lock", lock::OPS),
    ("php-std", php_std::OPS),
    ("query", query::OPS),
    ("span", span::OPS),
    ("di", di::OPS),
    ("registry", registry::OPS),
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
        "domains" => domains::call(op, args, session).await,
        "dsn" => dsn::call(op, args, session).await,
        "emails" => emails::call(op, args, session).await,
        "http" => http::call(op, args, session).await,
        "locale" => locale::call(op, args, session).await,
        "lock" => lock::call(op, args, session).await,
        "php-std" => php_std::call(op, args, session).await,
        "query" => query::call(op, args, session).await,
        "span" => span::call(op, args, session).await,
        "di" => di::call(op, args, session).await,
        "registry" => registry::call(op, args, session).await,
        "queue" => queue::call(op, args, session).await,
        "system" => system::call(op, args, session).await,
        "user-agent" => user_agent::call(op, args, session).await,
        "validators" => validators::call(op, args, session).await,
        _ => Err(Fault::new(format!("unknown library `{lib}`"))),
    }
}

/// The benchmarks of a library: name and what it times.
pub fn benches(lib: &str) -> &'static [(&'static str, &'static str)] {
    match lib {
        "dsn" => dsn::BENCHES,
        "auth" => auth::BENCHES,
        "cache" => cache::BENCHES,
        "domains" => domains::BENCHES,
        "emails" => emails::BENCHES,
        "http" => http::BENCHES,
        "locale" => locale::BENCHES,
        "lock" => lock::BENCHES,
        "php-std" => php_std::BENCHES,
        "span" => span::BENCHES,
        "di" => di::BENCHES,
        "registry" => registry::BENCHES,
        "system" => system::BENCHES,
        "user-agent" => user_agent::BENCHES,
        "validators" => validators::BENCHES,
        _ => &[],
    }
}

/// Sets up one benchmark of one library and returns the closure to time.
pub async fn bench(lib: &str, name: &str, session: &mut Session) -> Result<Bench, Fault> {
    match lib {
        "dsn" => dsn::bench(name, session).await,
        "auth" => auth::bench(name, session).await,
        "cache" => cache::bench(name, session).await,
        "domains" => domains::bench(name, session).await,
        "emails" => emails::bench(name, session).await,
        "http" => http::bench(name, session).await,
        "locale" => locale::bench(name, session).await,
        "lock" => lock::bench(name, session).await,
        "php-std" => php_std::bench(name, session).await,
        "span" => span::bench(name, session).await,
        "di" => di::bench(name, session).await,
        "registry" => registry::bench(name, session).await,
        "system" => system::bench(name, session).await,
        "user-agent" => user_agent::bench(name, session).await,
        "validators" => validators::bench(name, session).await,
        _ => Err(Fault::new(format!("{lib}: no benchmarks"))),
    }
}
