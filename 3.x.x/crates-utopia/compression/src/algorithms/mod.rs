#[cfg(feature = "brotli")]
pub mod brotli;
#[cfg(feature = "deflate")]
pub mod deflate;
#[cfg(feature = "gzip")]
pub mod gzip;
#[cfg(feature = "zstd")]
pub mod zstd;
