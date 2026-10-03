//! DSN parsing for Utopia.
//!
//! Rust port of [`utopia-php/dsn`](https://github.com/utopia-php/dsn).

mod dsn;
mod error;
mod parse;

pub use dsn::{Dsn, DSN};
pub use error::DsnError;
