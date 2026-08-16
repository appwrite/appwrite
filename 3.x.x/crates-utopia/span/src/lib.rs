//! Span tracing for Utopia.
//!
//! Rust port of [`utopia-php/span`](https://github.com/utopia-php/span).

mod attr;
mod error;
pub mod exporter;
mod level;
mod php_url;
mod span;
pub mod storage;

pub use attr::AttrValue;
pub use error::{SpanError, TraceFrame};
pub use exporter::{
    Exporter, None as NoneExporter, Pretty, Sentry, SentryError, SentryField, SentryLevel, Stdout,
};
pub use level::Level;
pub use span::Span;
pub use storage::{Auto, Coroutine, Memory, Storage};

/// Prelude for common span types.
pub mod prelude {
    pub use crate::{
        AttrValue, Auto, Exporter, Level, Memory, NoneExporter, Pretty, Sentry, Span, Stdout,
        Storage,
    };
}
