//! Appwrite locale helpers (stub).

use serde::{Deserialize, Serialize};

/// Locale code wrapper.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Locale {
    code: String,
}

impl Locale {
    /// Create a locale from a BCP-47-ish code (e.g. `en`).
    #[must_use]
    pub fn new(code: impl Into<String>) -> Self {
        Self { code: code.into() }
    }

    /// Locale code.
    #[must_use]
    pub fn code(&self) -> &str {
        &self.code
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> Locale {
    Locale::new("en")
}
