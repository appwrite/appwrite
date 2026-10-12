//! Compat adapter for `query`: replays the programs recorded from the PHP
//! tests (tests/compat/query/Replay.php is the PHP side) on `utopia-query`.
//!
//! [`engine`] runs a program: it numbers objects, decodes arguments, calls
//! back into the program when the library invokes a test closure or a test
//! object, and exports results the way the PHP replay does. Each area maps
//! PHP classes and methods onto the crate: [`core`] (queries, statements,
//! hooks), [`builder`], [`schema`], [`ast`], [`tokenizer`], [`classifier`].

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Outcome, Session};
use crate::bench::Bench;

mod ast;
mod builder;
mod classifier;
mod core;
pub mod engine;
mod schema;
mod tokenizer;

/// Operations this adapter implements (must match `spec.json`).
pub const OPS: &[&str] = &["ast", "builder", "classifier", "hook", "query", "schema", "tokenizer"];

/// Benchmarks: name and what each times.
pub const BENCHES: &[(&str, &str)] = &[];

pub async fn call(op: &str, args: &Value, _session: &mut Session) -> OpResult {
    if !OPS.contains(&op) {
        return Err(Fault::new(format!("query: unknown operation `{op}`")));
    }
    let program = args.get("program").and_then(Value::as_array).ok_or_else(|| Fault::new("missing `program`"))?;
    let impls = args.get("impls").cloned().unwrap_or(Value::Null);
    engine::run(program, &impls).map(Outcome::Ok)
}

pub async fn bench(name: &str, _session: &mut Session) -> Result<Bench, Fault> {
    Err(Fault::new(format!("query: no benchmark `{name}`")))
}

/// Constructs a library object of `class` (relative to `Utopia\Query\`).
pub(crate) fn construct(class: &str, args: &mut engine::Args) -> Option<engine::Outcome> {
    core::construct(class, args)
        .or_else(|| builder::construct(class, args))
        .or_else(|| schema::construct(class, args))
        .or_else(|| ast::construct(class, args))
        .or_else(|| tokenizer::construct(class, args))
        .or_else(|| classifier::construct(class, args))
}

/// Calls a static method of `class`.
pub(crate) fn call_static(class: &str, method: &str, args: &mut engine::Args) -> Option<engine::Outcome> {
    core::call_static(class, method, args)
        .or_else(|| builder::call_static(class, method, args))
        .or_else(|| schema::call_static(class, method, args))
        .or_else(|| ast::call_static(class, method, args))
        .or_else(|| tokenizer::call_static(class, method, args))
        .or_else(|| classifier::call_static(class, method, args))
}

/// A class constant (not an enum case) of `class`.
pub(crate) fn constant(class: &str, name: &str) -> Option<engine::Ret> {
    core::constant(class, name)
        .or_else(|| builder::constant(class, name))
        .or_else(|| schema::constant(class, name))
        .or_else(|| ast::constant(class, name))
        .or_else(|| tokenizer::constant(class, name))
        .or_else(|| classifier::constant(class, name))
}
