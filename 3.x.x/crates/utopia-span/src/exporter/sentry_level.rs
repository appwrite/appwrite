//! Levels accepted by Sentry's event API.

use crate::level::Level as SpanLevel;

/// PHP `Utopia\Span\Exporter\Sentry\Level`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SentryLevel {
    Debug,
    Info,
    Warning,
    Error,
    Fatal,
}

impl SentryLevel {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Debug => "debug",
            Self::Info => "info",
            Self::Warning => "warning",
            Self::Error => "error",
            Self::Fatal => "fatal",
        }
    }

    /// PHP `Level::fromSpan`.
    pub fn from_span(level: SpanLevel) -> Self {
        match level {
            SpanLevel::Debug => Self::Debug,
            SpanLevel::Info => Self::Info,
            SpanLevel::Warn => Self::Warning,
            SpanLevel::Error => Self::Error,
            SpanLevel::Fatal => Self::Fatal,
        }
    }
}
