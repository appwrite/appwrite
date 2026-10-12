//! [`Authorization`]: the roles of the current request and whether a
//! permission list grants an action to them.
//!
//! PHP: `Utopia\Database\Validator\Authorization` and
//! `Validator\Authorization\Input`. (Being ported; see the crate guide.)

use std::sync::Mutex;

/// The current roles and whether authorization is enforced.
pub struct Authorization {
    #[allow(dead_code)]
    inner: Mutex<()>,
}

impl Authorization {
    /// `new Authorization()`.
    pub fn new() -> Self {
        Self { inner: Mutex::new(()) }
    }
}

impl Default for Authorization {
    fn default() -> Self {
        Self::new()
    }
}
