//! Where a span attribute is placed in the Sentry event payload.

/// PHP `Utopia\Span\Exporter\SentryField`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SentryField {
    Tag,
    Context,
    Extra,
}
