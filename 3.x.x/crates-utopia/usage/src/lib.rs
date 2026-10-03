//! Usage metrics for Utopia.
//!
//! Rust port of [`utopia-php/usage`](https://github.com/utopia-php/usage) (PHP SHA `baeef33bbcb6`).

#![deny(unsafe_code)]
#![allow(
    clippy::too_many_arguments,
    clippy::too_many_lines,
    clippy::doc_markdown,
    clippy::assigning_clones,
    clippy::match_same_arms,
    clippy::map_unwrap_or,
    clippy::format_push_string,
    clippy::semicolon_if_nothing_returned
)]

pub mod accumulator;
pub mod adapter;
pub mod error;
pub mod metric;
pub mod tenant;
pub mod usage;
pub mod usage_query;

pub use accumulator::Accumulator;
pub use adapter::{Adapter, ClickHouse, DatabaseAdapter, Memory, SqlAdapter};
pub use error::{Result, UsageError};
pub use metric::Metric;
pub use tenant::Tenant;
pub use usage::{Usage, TYPE_EVENT, TYPE_GAUGE};
pub use usage_query::UsageQuery;

pub mod prelude {
    pub use crate::{
        Accumulator, Adapter, ClickHouse, DatabaseAdapter, Memory, Metric, Tenant, Usage,
        UsageError, UsageQuery,
    };
}
