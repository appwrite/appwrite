//! Response models (`Appwrite\Utopia\Response\Model\*`).
//!
//! Models borrow from the typed documents and serialise straight to JSON in
//! the rule order PHP uses, applying defaults, `sensitive` blanking and the
//! response-format compatibility filters. No intermediate document maps are
//! built.

mod models;

pub use models::*;

use chrono::NaiveDateTime;
use utopia_database::datetime;

/// A `X-Appwrite-Response-Format` version.
#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
pub struct Version(pub u32, pub u32, pub u32);

impl Version {
    pub fn parse(s: &str) -> Option<Self> {
        let mut parts = s.trim().split(['.', '-']).map(|p| p.parse::<u32>().ok());
        let major = parts.next()??;
        let minor = parts.next().flatten().unwrap_or(0);
        let patch = parts.next().flatten().unwrap_or(0);
        Some(Version(major, minor, patch))
    }
}

/// Rendering options shared by all models.
#[derive(Debug, Clone, Copy, Default)]
pub struct Render {
    /// Show `sensitive` fields (API keys and privileged users).
    pub sensitive: bool,
    /// Client response format, when it differs from the latest.
    pub format: Option<Version>,
}

impl Render {
    /// `true` when the client format is older than `version`.
    pub fn before(&self, major: u32, minor: u32, patch: u32) -> bool {
        matches!(self.format, Some(v) if v < Version(major, minor, patch))
    }
}

/// Datetime field (`''` when unset).
pub fn dt(value: &Option<NaiveDateTime>) -> String {
    value.as_ref().map(datetime::format_tz).unwrap_or_default()
}
