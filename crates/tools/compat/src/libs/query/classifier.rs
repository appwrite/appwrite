//! The `classifier` area of the query adapter (see [`super::engine`]).

use super::engine::{Args, Outcome, Ret};

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
