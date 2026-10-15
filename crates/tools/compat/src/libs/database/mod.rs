//! Compat adapter for `database`: maps `tests/compat/database/spec.json`
//! (and `spec.d/*.json`) operations onto `utopia-database` 8.
//!
//! Three vocabularies, as on the PHP side (`tests/compat/database/ops/`):
//! `core` ([`core`]: `db.*`, `database.*`, `db.authorization.*`),
//! `objects` ([`objects`]: `object.new`, `object.call`, `object.get`) and
//! `adapter` ([`adapter`]: `adapter.*` and the fuzz programs). Values cross
//! through [`codec`] and [`values`] (`Values.php`), arguments decode
//! through [`args`] (`Core::argument`), callbacks run through [`calls`]
//! (`Core::callback`), and code the library is given is a named fixture
//! ([`fixtures`], `Fixtures.php`).

use std::sync::Arc;

use futures_util::future::BoxFuture;
use serde_json::Value as Json;

use crate::adapter::{OpResult, Session};

pub mod adapter;
pub mod args;
pub mod calls;
pub mod codec;
pub mod convert;
pub mod core;
pub mod ctx;
pub mod database;
pub mod enums;
pub mod fixtures;
pub mod obj;
pub mod objects;
pub mod open;
mod ops;
pub mod unreachable;
pub mod values;

mod benches;

use ctx::{Ctx, R};
use obj::Obj;

/// Every operation (`spec.d/*.json`).
pub const OPS: &[&str] = ops::OPS;

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = benches::BENCHES;

/// Runs one operation for the driver.
pub async fn call(op: &str, args: &Json, session: &mut Session) -> OpResult {
    let cx = Ctx::enter(session);
    ctx::outcome(dispatch(op, args, &cx).await)
}

/// Runs one operation: any operation, from the driver or a callback's step
/// (`Core::operations`). `as` stores the raw result for `{"$obj": <name>}`.
pub fn dispatch<'a>(op: &'a str, json: &'a Json, cx: &'a Arc<Ctx>) -> BoxFuture<'a, R<Obj>> {
    Box::pin(async move {
        let store = json.get("as").and_then(Json::as_str).map(str::to_owned);
        let a = args::Args::new(cx, json)?;
        let result = if op.starts_with("object.") {
            objects::call(op, &a).await
        } else if op.starts_with("adapter.") || op.starts_with("mongo.") {
            adapter::call(op, &a).await
        } else if op.starts_with("db.") || op.starts_with("database.") {
            core::call(op, json, &a).await
        } else {
            ctx::fault(format!("database: unknown operation `{op}`"))
        }?;
        if let Some(name) = store {
            cx.store(&name, result.clone().into_result());
        }
        Ok(result)
    })
}

#[cfg(test)]
mod size_probe {
    #[test]
    fn future_sizes() {
        let mut session = crate::adapter::Session::default();
        let cx = super::ctx::Ctx::enter(&mut session);
        let json = serde_json::json!({});
        let a = super::args::Args::new(&cx, &json).unwrap();
        let db = utopia_database::Database::new(
            std::sync::Arc::new(utopia_database::adapter::memory::Memory::new()),
            std::sync::Arc::new(utopia_cache::Cache::from_dyn(std::sync::Arc::new(utopia_cache::Memory::new()))),
        );
        eprintln!("database::invoke {}", std::mem::size_of_val(&super::database::invoke(&db, "x", &a)));
        eprintln!("core::call {}", std::mem::size_of_val(&super::core::call("x", &json, &a)));
        eprintln!("objects::call {}", std::mem::size_of_val(&super::objects::call("x", &a)));
        eprintln!("adapter::invoke {}", std::mem::size_of_val(&super::adapter::invoke(db.adapter(), "x", &a)));
        eprintln!("find {}", std::mem::size_of_val(&db.find("x", vec![], utopia_database::PermissionType::Read)));
        eprintln!("create_documents {}", std::mem::size_of_val(&db.create_documents("x", vec![], 1, None)));
        eprintln!(
            "create_collection {}",
            std::mem::size_of_val(&db.create_collection(
                utopia_database::Collection::from_document(&utopia_database::Document::empty()).unwrap()
            ))
        );
        eprintln!("upsert_documents {}", std::mem::size_of_val(&db.upsert_documents("x", vec![], 1, None, None)));
    }
}

/// Runs one benchmark.
pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, crate::adapter::Fault> {
    benches::bench(name, session).await
}
