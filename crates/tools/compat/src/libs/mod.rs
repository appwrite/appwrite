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
pub mod agents;
pub mod audit;
pub mod auth;
pub mod balancer;
pub mod bus;
pub mod cache;
pub mod cdn;
pub mod circuit_breaker;
pub mod cli;
pub mod client;
pub mod compression;
pub mod config;
pub mod console;
pub mod database;
pub mod detector;
pub mod di;
pub mod dns;
pub mod domains;
pub mod dsn;
pub mod emails;
pub mod http;
pub mod image;
pub mod locale;
pub mod lock;
pub mod messaging;
pub mod mongo;
pub mod mqtt;
pub mod nats;
pub mod openapi;
pub mod orchestration;
pub mod php_std;
pub mod platform;
pub mod pools;
pub mod psr7;
pub mod query;
pub mod queue;
pub mod registry;
pub mod schedule;
pub mod servers;
pub mod smtp;
pub mod span;
pub mod storage;
pub mod system;
pub mod telemetry;
pub mod usage;
pub mod user_agent;
pub mod validators;
pub mod vcs;
pub mod websocket;

/// Library ids (the `tests/compat/<lib>` directory names) and their operations.
pub const LIBS: &[(&str, &[&str])] = &[
    ("abuse", abuse::OPS),
    ("agents", agents::OPS),
    ("audit", audit::OPS),
    ("auth", auth::OPS),
    ("balancer", balancer::OPS),
    ("bus", bus::OPS),
    ("cache", cache::OPS),
    ("cdn", cdn::OPS),
    ("circuit-breaker", circuit_breaker::OPS),
    ("cli", cli::OPS),
    ("client", client::OPS),
    ("compression", compression::OPS),
    ("config", config::OPS),
    ("console", console::OPS),
    ("database", database::OPS),
    ("detector", detector::OPS),
    ("di", di::OPS),
    ("dns", dns::OPS),
    ("domains", domains::OPS),
    ("dsn", dsn::OPS),
    ("emails", emails::OPS),
    ("http", http::OPS),
    ("image", image::OPS),
    ("locale", locale::OPS),
    ("mongo", mongo::OPS),
    ("lock", lock::OPS),
    ("messaging", messaging::OPS),
    ("mqtt", mqtt::OPS),
    ("nats", nats::OPS),
    ("openapi", openapi::OPS),
    ("orchestration", orchestration::OPS),
    ("php-std", php_std::OPS),
    ("platform", platform::OPS),
    ("pools", pools::OPS),
    ("psr7", psr7::OPS),
    ("query", query::OPS),
    ("queue", queue::OPS),
    ("registry", registry::OPS),
    ("schedule", schedule::OPS),
    ("servers", servers::OPS),
    ("smtp", smtp::OPS),
    ("span", span::OPS),
    ("storage", storage::OPS),
    ("system", system::OPS),
    ("telemetry", telemetry::OPS),
    ("usage", usage::OPS),
    ("user-agent", user_agent::OPS),
    ("validators", validators::OPS),
    ("vcs", vcs::OPS),
    ("websocket", websocket::OPS),
];

/// Runs one operation of one library.
pub async fn call(lib: &str, op: &str, args: &Value, session: &mut Session) -> OpResult {
    match lib {
        "abuse" => abuse::call(op, args, session).await,
        "agents" => agents::call(op, args, session).await,
        "audit" => audit::call(op, args, session).await,
        "auth" => auth::call(op, args, session).await,
        "balancer" => balancer::call(op, args, session).await,
        "bus" => bus::call(op, args, session).await,
        "cache" => cache::call(op, args, session).await,
        "cdn" => cdn::call(op, args, session).await,
        "circuit-breaker" => circuit_breaker::call(op, args, session).await,
        "cli" => cli::call(op, args, session).await,
        "client" => client::call(op, args, session).await,
        "compression" => compression::call(op, args, session).await,
        "config" => config::call(op, args, session).await,
        "console" => console::call(op, args, session).await,
        "database" => database::call(op, args, session).await,
        "detector" => detector::call(op, args, session).await,
        "di" => di::call(op, args, session).await,
        "dns" => dns::call(op, args, session).await,
        "domains" => domains::call(op, args, session).await,
        "dsn" => dsn::call(op, args, session).await,
        "emails" => emails::call(op, args, session).await,
        "http" => http::call(op, args, session).await,
        "image" => image::call(op, args, session).await,
        "locale" => locale::call(op, args, session).await,
        "mongo" => mongo::call(op, args, session).await,
        "lock" => lock::call(op, args, session).await,
        "messaging" => messaging::call(op, args, session).await,
        "mqtt" => mqtt::call(op, args, session).await,
        "nats" => nats::call(op, args, session).await,
        "openapi" => openapi::call(op, args, session).await,
        "orchestration" => orchestration::call(op, args, session).await,
        "php-std" => php_std::call(op, args, session).await,
        "platform" => platform::call(op, args, session).await,
        "pools" => pools::call(op, args, session).await,
        "psr7" => psr7::call(op, args, session).await,
        "query" => query::call(op, args, session).await,
        "queue" => queue::call(op, args, session).await,
        "registry" => registry::call(op, args, session).await,
        "schedule" => schedule::call(op, args, session).await,
        "servers" => servers::call(op, args, session).await,
        "smtp" => smtp::call(op, args, session).await,
        "span" => span::call(op, args, session).await,
        "storage" => storage::call(op, args, session).await,
        "system" => system::call(op, args, session).await,
        "telemetry" => telemetry::call(op, args, session).await,
        "usage" => usage::call(op, args, session).await,
        "user-agent" => user_agent::call(op, args, session).await,
        "validators" => validators::call(op, args, session).await,
        "vcs" => vcs::call(op, args, session).await,
        "websocket" => websocket::call(op, args, session).await,
        _ => Err(Fault::new(format!("unknown library `{lib}`"))),
    }
}

/// The benchmarks of a library: name and what it times.
pub fn benches(lib: &str) -> &'static [(&'static str, &'static str)] {
    match lib {
        "abuse" => abuse::BENCHES,
        "agents" => agents::BENCHES,
        "audit" => audit::BENCHES,
        "auth" => auth::BENCHES,
        "balancer" => balancer::BENCHES,
        "bus" => bus::BENCHES,
        "cache" => cache::BENCHES,
        "cdn" => cdn::BENCHES,
        "circuit-breaker" => circuit_breaker::BENCHES,
        "cli" => cli::BENCHES,
        "client" => client::BENCHES,
        "compression" => compression::BENCHES,
        "config" => config::BENCHES,
        "database" => database::BENCHES,
        "console" => console::BENCHES,
        "detector" => detector::BENCHES,
        "di" => di::BENCHES,
        "dns" => dns::BENCHES,
        "domains" => domains::BENCHES,
        "dsn" => dsn::BENCHES,
        "emails" => emails::BENCHES,
        "http" => http::BENCHES,
        "image" => image::BENCHES,
        "locale" => locale::BENCHES,
        "mongo" => mongo::BENCHES,
        "lock" => lock::BENCHES,
        "messaging" => messaging::BENCHES,
        "mqtt" => mqtt::BENCHES,
        "nats" => nats::BENCHES,
        "openapi" => openapi::BENCHES,
        "orchestration" => orchestration::BENCHES,
        "php-std" => php_std::BENCHES,
        "platform" => platform::BENCHES,
        "pools" => pools::BENCHES,
        "psr7" => psr7::BENCHES,
        "query" => query::BENCHES,
        "queue" => queue::BENCHES,
        "registry" => registry::BENCHES,
        "schedule" => schedule::BENCHES,
        "servers" => servers::BENCHES,
        "smtp" => smtp::BENCHES,
        "span" => span::BENCHES,
        "storage" => storage::BENCHES,
        "system" => system::BENCHES,
        "telemetry" => telemetry::BENCHES,
        "usage" => usage::BENCHES,
        "user-agent" => user_agent::BENCHES,
        "validators" => validators::BENCHES,
        "vcs" => vcs::BENCHES,
        "websocket" => websocket::BENCHES,
        _ => &[],
    }
}

/// Sets up one benchmark of one library and returns the closure to time.
pub async fn bench(lib: &str, name: &str, session: &mut Session) -> Result<Bench, Fault> {
    match lib {
        "abuse" => abuse::bench(name, session).await,
        "agents" => agents::bench(name, session).await,
        "audit" => audit::bench(name, session).await,
        "auth" => auth::bench(name, session).await,
        "balancer" => balancer::bench(name, session).await,
        "bus" => bus::bench(name, session).await,
        "cache" => cache::bench(name, session).await,
        "cdn" => cdn::bench(name, session).await,
        "circuit-breaker" => circuit_breaker::bench(name, session).await,
        "cli" => cli::bench(name, session).await,
        "client" => client::bench(name, session).await,
        "compression" => compression::bench(name, session).await,
        "config" => config::bench(name, session).await,
        "database" => database::bench(name, session).await,
        "console" => console::bench(name, session).await,
        "detector" => detector::bench(name, session).await,
        "di" => di::bench(name, session).await,
        "dns" => dns::bench(name, session).await,
        "domains" => domains::bench(name, session).await,
        "dsn" => dsn::bench(name, session).await,
        "emails" => emails::bench(name, session).await,
        "http" => http::bench(name, session).await,
        "image" => image::bench(name, session).await,
        "locale" => locale::bench(name, session).await,
        "mongo" => mongo::bench(name, session).await,
        "lock" => lock::bench(name, session).await,
        "messaging" => messaging::bench(name, session).await,
        "mqtt" => mqtt::bench(name, session).await,
        "nats" => nats::bench(name, session).await,
        "openapi" => openapi::bench(name, session).await,
        "orchestration" => orchestration::bench(name, session).await,
        "php-std" => php_std::bench(name, session).await,
        "platform" => platform::bench(name, session).await,
        "pools" => pools::bench(name, session).await,
        "psr7" => psr7::bench(name, session).await,
        "query" => query::bench(name, session).await,
        "queue" => queue::bench(name, session).await,
        "registry" => registry::bench(name, session).await,
        "schedule" => schedule::bench(name, session).await,
        "servers" => servers::bench(name, session).await,
        "smtp" => smtp::bench(name, session).await,
        "span" => span::bench(name, session).await,
        "storage" => storage::bench(name, session).await,
        "system" => system::bench(name, session).await,
        "telemetry" => telemetry::bench(name, session).await,
        "usage" => usage::bench(name, session).await,
        "user-agent" => user_agent::bench(name, session).await,
        "validators" => validators::bench(name, session).await,
        "vcs" => vcs::bench(name, session).await,
        "websocket" => websocket::bench(name, session).await,
        _ => Err(Fault::new(format!("{lib}: no benchmarks"))),
    }
}
