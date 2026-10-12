//! Compat adapter for `database`: maps `tests/compat/database/spec.json`
//! (and `spec.d/*.json`) operations onto `utopia-database`.
//!
//! One module per area, each exposing `OPS` and `call`, the twins of
//! `tests/compat/database/ops/<area>.php`. Values cross through [`codec`],
//! the twin of `tests/compat/database/Values.php`.

use serde_json::Value;

use crate::adapter::{Fault, OpResult, Session};

pub mod codec;

mod adapter;
mod benches;
mod core;
mod helpers;
mod mongo;
mod validators;
mod values;

const AREAS: &[&[&str]] = &[adapter::OPS, core::OPS, helpers::OPS, mongo::OPS, validators::OPS, values::OPS];

/// Every operation of every area.
pub const OPS: &[&str] = &concat_ops();

/// Benchmarks (same names and work as `benchmarks()` in Adapter.php).
pub const BENCHES: &[(&str, &str)] = benches::BENCHES;

pub async fn call(op: &str, args: &Value, session: &mut Session) -> OpResult {
    if adapter::OPS.contains(&op) {
        adapter::call(op, args, session).await
    } else if core::OPS.contains(&op) {
        core::call(op, args, session).await
    } else if mongo::OPS.contains(&op) {
        mongo::call(op, args, session).await
    } else if helpers::OPS.contains(&op) {
        helpers::call(op, args, session).await
    } else if validators::OPS.contains(&op) {
        validators::call(op, args, session).await
    } else if values::OPS.contains(&op) {
        values::call(op, args, session).await
    } else {
        Err(Fault::new(format!("database: unknown operation `{op}`")))
    }
}

pub async fn bench(name: &str, session: &mut Session) -> Result<crate::bench::Bench, Fault> {
    benches::bench(name, session).await
}

const fn ops_len() -> usize {
    let mut n = 0;
    let mut i = 0;
    while i < AREAS.len() {
        n += AREAS[i].len();
        i += 1;
    }
    n
}

const fn concat_ops() -> [&'static str; ops_len()] {
    let mut out = [""; ops_len()];
    let mut k = 0;
    let mut i = 0;
    while i < AREAS.len() {
        let mut j = 0;
        while j < AREAS[i].len() {
            out[k] = AREAS[i][j];
            k += 1;
            j += 1;
        }
        i += 1;
    }
    out
}
