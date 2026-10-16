//! Severity level of a span (PHP `Utopia\Span\Level`).

/// Names follow Grafana Loki's `detected_level` vocabulary (`warn`, not `warning`).
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum Level {
    Debug,
    Info,
    Warn,
    Error,
    Fatal,
}

impl Level {
    /// PHP enum string value.
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Debug => "debug",
            Self::Info => "info",
            Self::Warn => "warn",
            Self::Error => "error",
            Self::Fatal => "fatal",
        }
    }

    /// PHP `Level::tryFrom((string) $span->get('level'))`.
    pub fn try_from_attr(value: &str) -> Option<Self> {
        match value {
            "debug" => Some(Self::Debug),
            "info" => Some(Self::Info),
            "warn" => Some(Self::Warn),
            "error" => Some(Self::Error),
            "fatal" => Some(Self::Fatal),
            _ => None,
        }
    }
}
