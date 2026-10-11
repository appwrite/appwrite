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

pub mod abuse;
pub mod auth;
pub mod cache;
pub mod circuit_breaker;
pub mod compression;
pub mod config;
pub mod database;
pub mod di;
pub mod domains;
pub mod dsn;
pub mod emails;
pub mod http;
pub mod image;
pub mod locale;
pub mod lock;
pub mod php_std;
pub mod pools;
pub mod query;
pub mod queue;
pub mod registry;
pub mod span;
pub mod system;
pub mod telemetry;
pub mod user_agent;
pub mod validators;

/// Library ids (the `tests/compat/<lib>` directory names) and their operations.
pub const LIBS: &[(&str, &[&str])] = &[
    ("abuse", abuse::OPS),
    ("auth", auth::OPS),
    ("cache", cache::OPS),
    ("circuit-breaker", circuit_breaker::OPS),
    ("compression", compression::OPS),
    ("config", config::OPS),
    ("database", database::OPS),
    ("di", di::OPS),
    ("domains", domains::OPS),
    ("dsn", dsn::OPS),
    ("emails", emails::OPS),
    ("http", http::OPS),
    ("image", image::OPS),
    ("locale", locale::OPS),
    ("lock", lock::OPS),
    ("php-std", php_std::OPS),
    ("pools", pools::OPS),
    ("query", query::OPS),
    ("queue", queue::OPS),
    ("registry", registry::OPS),
    ("span", span::OPS),
    ("system", system::OPS),
    ("telemetry", telemetry::OPS),
    ("user-agent", user_agent::OPS),
    ("validators", validators::OPS),
];

/// Runs one operation of one library.
pub async fn call(lib: &str, op: &str, args: &Value, session: &mut Session) -> OpResult {
    match lib {
        "abuse" => abuse::call(op, args, session).await,
        "auth" => auth::call(op, args, session).await,
        "cache" => cache::call(op, args, session).await,
        "circuit-breaker" => circuit_breaker::call(op, args, session).await,
        "compression" => compression::call(op, args, session).await,
        "config" => config::call(op, args, session).await,
        "database" => database::call(op, args, session).await,
        "di" => di::call(op, args, session).await,
        "domains" => domains::call(op, args, session).await,
        "dsn" => dsn::call(op, args, session).await,
        "emails" => emails::call(op, args, session).await,
        "http" => http::call(op, args, session).await,
        "image" => image::call(op, args, session).await,
        "locale" => locale::call(op, args, session).await,
        "lock" => lock::call(op, args, session).await,
        "php-std" => php_std::call(op, args, session).await,
        "pools" => pools::call(op, args, session).await,
        "query" => query::call(op, args, session).await,
        "queue" => queue::call(op, args, session).await,
        "registry" => registry::call(op, args, session).await,
        "span" => span::call(op, args, session).await,
        "system" => system::call(op, args, session).await,
        "telemetry" => telemetry::call(op, args, session).await,
        "user-agent" => user_agent::call(op, args, session).await,
        "validators" => validators::call(op, args, session).await,
        _ => Err(Fault::new(format!("unknown library `{lib}`"))),
    }
}

/// The benchmarks of a library: name and what it times.
pub fn benches(lib: &str) -> &'static [(&'static str, &'static str)] {
    match lib {
        "abuse" => abuse::BENCHES,
        "auth" => auth::BENCHES,
        "cache" => cache::BENCHES,
        "circuit-breaker" => circuit_breaker::BENCHES,
        "compression" => compression::BENCHES,
        "config" => config::BENCHES,
        "di" => di::BENCHES,
        "domains" => domains::BENCHES,
        "dsn" => dsn::BENCHES,
        "emails" => emails::BENCHES,
        "http" => http::BENCHES,
        "image" => image::BENCHES,
        "locale" => locale::BENCHES,
        "lock" => lock::BENCHES,
        "php-std" => php_std::BENCHES,
        "pools" => pools::BENCHES,
        "queue" => queue::BENCHES,
        "registry" => registry::BENCHES,
        "span" => span::BENCHES,
        "system" => system::BENCHES,
        "telemetry" => telemetry::BENCHES,
        "user-agent" => user_agent::BENCHES,
        "validators" => validators::BENCHES,
        _ => &[],
    }
}

/// Sets up one benchmark of one library and returns the closure to time.
pub async fn bench(lib: &str, name: &str, session: &mut Session) -> Result<Bench, Fault> {
    match lib {
        "abuse" => abuse::bench(name, session).await,
        "auth" => auth::bench(name, session).await,
        "cache" => cache::bench(name, session).await,
        "circuit-breaker" => circuit_breaker::bench(name, session).await,
        "compression" => compression::bench(name, session).await,
        "config" => config::bench(name, session).await,
        "di" => di::bench(name, session).await,
        "domains" => domains::bench(name, session).await,
        "dsn" => dsn::bench(name, session).await,
        "emails" => emails::bench(name, session).await,
        "http" => http::bench(name, session).await,
        "image" => image::bench(name, session).await,
        "locale" => locale::bench(name, session).await,
        "lock" => lock::bench(name, session).await,
        "php-std" => php_std::bench(name, session).await,
        "pools" => pools::bench(name, session).await,
        "queue" => queue::bench(name, session).await,
        "registry" => registry::bench(name, session).await,
        "span" => span::bench(name, session).await,
        "system" => system::bench(name, session).await,
        "telemetry" => telemetry::bench(name, session).await,
        "user-agent" => user_agent::bench(name, session).await,
        "validators" => validators::bench(name, session).await,
        _ => Err(Fault::new(format!("{lib}: no benchmarks"))),
    }
}
