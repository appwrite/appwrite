//! Appwrite lifecycle hooks (stub).

/// Named hook identifier.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Hook {
    name: String,
}

impl Hook {
    /// Create a hook with the given name.
    #[must_use]
    pub fn new(name: impl Into<String>) -> Self {
        Self { name: name.into() }
    }

    /// Hook name.
    #[must_use]
    pub fn name(&self) -> &str {
        &self.name
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> Hook {
    Hook::new("appwrite-hooks stub")
}
