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
#[allow(clippy::module_inception)]
pub mod database;
pub mod enums;
pub mod fixtures;
pub mod mirror;
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

/// Runs one benchmark.
pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, crate::adapter::Fault> {
    benches::bench(name, session).await
}
