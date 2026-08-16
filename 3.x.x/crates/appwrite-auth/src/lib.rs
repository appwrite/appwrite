//! Appwrite authentication helpers (stub).

use appwrite_exception::Exception;

/// Auth context placeholder.
#[derive(Debug, Clone, Default)]
pub struct Auth;

impl Auth {
    /// Create an empty auth context.
    #[must_use]
    pub fn new() -> Self {
        Self
    }

    /// Stub check that always succeeds.
    ///
    /// Touches utopia-auth / utopia-validators so the stub keeps those deps live.
    pub fn ensure_ready(&self) -> Result<(), Exception> {
        let _ = std::any::type_name::<utopia_auth::AuthError>();
        let _ = utopia_validators::Text::new(1);
        Ok(())
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> Auth {
    Auth::new()
}
