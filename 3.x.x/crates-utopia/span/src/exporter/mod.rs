//! Span exporters (PHP `Utopia\Span\Exporter`).

mod none;
mod pretty;
mod sentry;
mod sentry_field;
mod sentry_level;
mod stdout;

pub use none::None;
pub use pretty::Pretty;
pub use sentry::{Sentry, SentryError};
pub use sentry_field::SentryField;
pub use sentry_level::SentryLevel;
pub use stdout::Stdout;

use crate::span::Span;

/// PHP `Utopia\Span\Exporter\Exporter`.
pub trait Exporter: Send + Sync {
    fn export(&self, span: &Span);
    fn sample(&self, span: &Span) -> bool;
}
