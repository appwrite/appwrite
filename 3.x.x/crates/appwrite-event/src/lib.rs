//! Appwrite event payloads (stub).

use serde::{Deserialize, Serialize};
use thiserror::Error;

/// Event construction / parse error.
#[derive(Debug, Error)]
pub enum EventError {
    /// Invalid event name or payload.
    #[error("{0}")]
    Invalid(String),
}

/// Lightweight event envelope.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
pub struct Event {
    name: String,
    payload: serde_json::Value,
}

impl Event {
    /// Create an event with a JSON payload.
    pub fn new(name: impl Into<String>, payload: serde_json::Value) -> Result<Self, EventError> {
        let name = name.into();
        if name.is_empty() {
            return Err(EventError::Invalid("event name must not be empty".into()));
        }
        Ok(Self { name, payload })
    }

    /// Event name (e.g. `users.[userId].create`).
    #[must_use]
    pub fn name(&self) -> &str {
        &self.name
    }

    /// Event payload.
    #[must_use]
    pub fn payload(&self) -> &serde_json::Value {
        &self.payload
    }
}

/// Placeholder used by early stubs.
#[must_use]
pub fn stub() -> Event {
    Event::new("stub", serde_json::json!({})).expect("stub event")
}
