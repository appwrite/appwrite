//! The `builder` area of the query adapter (see [`super::engine`]).

use super::engine::{Args, Object, Outcome, Ret, fault};
use utopia_query::Query;

/// `new Class(...)` for the area's classes.
pub(crate) fn construct(_class: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// `Class::method(...)` for the area's classes.
pub(crate) fn call_static(_class: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// `Class::CONSTANT` for the area's classes.
pub(crate) fn constant(_class: &str, _name: &str) -> Option<Ret> {
    None
}

/// `Class::Case->method(...)` (and `->value` as method `->value`) for the area's enums.
pub(crate) fn enum_call(_class: &str, _case: &str, _method: &str, _args: &mut Args) -> Option<Outcome> {
    None
}

/// `$query->compile($builder)`: compiles `query` with the builder `object`.
pub(crate) fn compile_with(object: &mut dyn Object, _query: &Query) -> Outcome {
    Err(fault(format!("{} is not a compiler", object.class())))
}
