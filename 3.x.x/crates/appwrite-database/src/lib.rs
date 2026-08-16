//! Appwrite database helpers (stub).

use appwrite_exception::Exception;
use utopia_database::Document;

/// Thin wrapper around utopia-database for Appwrite-specific helpers.
#[derive(Debug, Clone, Default)]
pub struct DatabaseService;

impl DatabaseService {
    /// Create a service placeholder.
    #[must_use]
    pub fn new() -> Self {
        Self
    }

    /// Stub that constructs an empty utopia document and validates text length rules exist.
    pub fn ping(&self) -> Result<Document, Exception> {
        let _ = utopia_validators::Text::new(1);
        Ok(Document::new())
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> DatabaseService {
    DatabaseService::new()
}
