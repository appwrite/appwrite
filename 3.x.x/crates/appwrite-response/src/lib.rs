//! Appwrite API response models (stub).

use serde::{Deserialize, Serialize};
use serde_json::Value;

/// Generic JSON response model placeholder.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Model {
    name: String,
    data: Value,
}

impl Model {
    /// Create a named model wrapping arbitrary JSON.
    #[must_use]
    pub fn new(name: impl Into<String>, data: Value) -> Self {
        Self {
            name: name.into(),
            data,
        }
    }

    /// Model name (e.g. `user`, `teamList`).
    #[must_use]
    pub fn name(&self) -> &str {
        &self.name
    }

    /// Underlying JSON payload.
    #[must_use]
    pub fn data(&self) -> &Value {
        &self.data
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> Model {
    Model::new("stub", serde_json::json!({}))
}
