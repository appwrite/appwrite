//! The builder hooks the SQL adapters scope their statements with.
//!
//! PHP: `Utopia\Database\Adapter\SQL\Hook\*`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Hook\Column\AllowNull` | [`column::AllowNull`] |
//! | `Hook\Join\Chain` | [`join::Chain`] |
//! | `Hook\Join\OuterChain` | [`join::OuterChain`] |
//! | `Hook\Permission\Filter` | [`permission::Filter`] |
//! | `Hook\Permission\Join` | [`permission::Join`] |
//! | `Hook\Permission\OuterJoin` | [`permission::OuterJoin`] |
//! | `Postgres::newPermissionHook()`'s anonymous `Permission\Filter` | [`permission::Filter::jsonb`] |
//! | `Hook\Tenant\Filter` | [`tenant::Filter`] |
//! | `Hook\Tenant\OuterJoin` | [`tenant::OuterJoin`] |
//! | `Hook\Tenant\Raw` | [`tenant::Raw`] |
//! | `Hook\Tenant\RawOuterJoin` | [`tenant::RawOuterJoin`] |
//!
//! The hooks implement `utopia_query`'s [`Filter`](utopia_query::hook::Filter)
//! and [`JoinFilter`](utopia_query::hook::JoinFilter). A database error a
//! hook raises while a builder compiles (PHP throws it straight through the
//! builder) crosses the builder's error type as a `utopia_query::Error::Query`
//! wrapping it as its previous error, which
//! [`query_error`](crate::query) turns back into the database error.

use std::sync::Arc;

pub mod column;
pub mod join;
pub mod permission;
pub mod tenant;

/// A database error raised inside a hook, carried through a builder: a
/// `utopia_query::Error::Query` with its message and code, wrapping it.
pub(crate) fn hook_error(error: crate::Error) -> utopia_query::Error {
    let message = error.message().to_owned();
    let code = error.code();
    utopia_query::Error::with_previous(message, code, Arc::new(error))
}

/// Whether `name` matches `/^[first][rest]*$/`, where `$` also matches
/// before a final newline (PCRE's default).
pub(crate) fn matches_identifier(name: &str, first: fn(u8) -> bool, rest: fn(u8) -> bool) -> bool {
    let bytes = name.strip_suffix('\n').unwrap_or(name).as_bytes();
    match bytes.split_first() {
        Some((head, tail)) => first(*head) && tail.iter().all(|b| rest(*b)),
        None => false,
    }
}

/// `[a-zA-Z0-9_\-]`.
pub(crate) fn is_word_or_dash(b: u8) -> bool {
    b.is_ascii_alphanumeric() || b == b'_' || b == b'-'
}

/// `[a-zA-Z0-9_.\-]`.
pub(crate) fn is_name_byte(b: u8) -> bool {
    is_word_or_dash(b) || b == b'.'
}

/// `[a-zA-Z_]`.
pub(crate) fn is_name_start(b: u8) -> bool {
    b.is_ascii_alphabetic() || b == b'_'
}
