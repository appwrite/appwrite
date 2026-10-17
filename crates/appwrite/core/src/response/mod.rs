//! Response models (`Appwrite\Utopia\Response\Model\*`).
//!
//! Models borrow from the typed documents and serialise straight to JSON in
//! the rule order PHP uses, applying defaults, `sensitive` blanking and the
//! response-format compatibility filters. No intermediate document maps are
//! built.

mod models;

pub use models::*;

use crate::datetime;
use chrono::NaiveDateTime;

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
    /// `true` when the client format is older than `version` (never while
    /// [`current`] renders).
    pub fn before(&self, major: u32, minor: u32, patch: u32) -> bool {
        !CURRENT.with(std::cell::Cell::get) && matches!(self.format, Some(v) if v < Version(major, minor, patch))
    }
}

thread_local! {
    /// Set while [`current`] serializes a model.
    static CURRENT: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
}

/// `model` as the latest format renders it, whatever the client's: the
/// event payload an action sets in PHP (`$response->output()`, before the
/// format filters; without one, events carry the filtered response).
pub fn current<T: serde::Serialize>(model: &T) -> Vec<u8> {
    struct Reset;
    impl Drop for Reset {
        fn drop(&mut self) {
            CURRENT.with(|c| c.set(false));
        }
    }
    CURRENT.with(|c| c.set(true));
    let _reset = Reset;
    crate::json::to_vec(model)
}

/// Datetime field (`''` when unset).
pub fn dt(value: &Option<NaiveDateTime>) -> String {
    value.as_ref().map(datetime::format_tz).unwrap_or_default()
}
