//! Audit logs for Utopia.
//!
//! Rust port of [`utopia-php/audit`](https://github.com/utopia-php/monorepo/tree/main/packages/audit)
//! (PHP SHA `c3ae00025014`).

#![deny(unsafe_code)]

pub mod adapter;
pub mod audit;
pub mod error;
pub mod log;
pub mod query;

pub use adapter::{Adapter, ClickHouse, DatabaseAdapter, Memory, ParsedResource, SqlAdapter};
pub use audit::Audit;
pub use error::{AuditError, Result};
pub use log::Log;
pub use query::Query;

pub mod prelude {
    pub use crate::{
        Adapter, Audit, AuditError, ClickHouse, DatabaseAdapter, Log, Memory, Query, SqlAdapter,
    };
}
