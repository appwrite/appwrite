//! The writes' tests on the real Memory adapter: PHP's unit tests that run
//! on `Adapter\Memory` (and the Memory case of those parameterized over an
//! engine). Tests PHP runs on SQLite only, where the behaviour does not
//! depend on the engine, run here on Memory too.

mod authorize;
mod bulk;
mod bulk_more;
mod counter_range;
mod delete_race;
mod engine;
mod ids;
mod integer_rounding;
mod numeric;
mod relationship_queries;
mod toggles;

use crate::error::{Kind, Result};
use crate::value::{Str, Value};

/// A string value.
pub(super) fn s(text: &str) -> Value {
    Value::Str(Str::from(text))
}

/// Asserts `result` failed with an error of `kind` and `message`.
#[track_caller]
pub(super) fn refused<T: std::fmt::Debug>(result: Result<T>, kind: Kind, message: &str) {
    let err = result.expect_err("refused");
    assert_eq!((err.kind(), err.message()), (kind, message));
}
