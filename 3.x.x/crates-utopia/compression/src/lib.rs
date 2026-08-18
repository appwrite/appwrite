//! Compression algorithms and `Accept-Encoding` negotiation.
//!
//! Rust port of [`utopia-php/compression`](https://github.com/utopia-php/compression).

mod algorithms;
mod compression;
mod error;

pub use compression::Compression;
pub use compression::{BROTLI, DEFLATE, GZIP, IDENTITY, NONE, ZSTD};
pub use error::CompressionError;

/// Prelude for common compression types.
pub mod prelude {
    pub use crate::{Compression, CompressionError};
}
