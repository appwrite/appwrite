//! Base64-encodable key/value authentication state store.

use std::collections::HashMap;

use base64::engine::general_purpose::STANDARD;
use base64::Engine;
use serde_json::Value;

use crate::error::AuthError;

/// Serializable property map for authentication state.
#[derive(Debug, Clone, Default)]
pub struct Store {
    data: HashMap<String, Value>,
    key: Option<String>,
}

impl Store {
    /// Create an empty store.
    #[must_use]
    pub fn new() -> Self {
        Self::default()
    }

    /// Read a property value.
    #[must_use]
    pub fn get_property(&self, key: &str) -> Option<&Value> {
        self.data.get(key)
    }

    /// Set a property value.
    pub fn set_property(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self {
        self.data.insert(key.into(), value.into());
        self
    }

    /// Read the store encryption key, if any.
    #[must_use]
    pub fn key(&self) -> Option<&str> {
        self.key.as_deref()
    }

    /// Set the store encryption key.
    pub fn set_key(&mut self, key: Option<impl Into<String>>) -> &mut Self {
        self.key = key.map(Into::into);
        self
    }

    /// Encode store data as a base64 JSON string.
    pub fn encode(&self) -> Result<String, AuthError> {
        let json = serde_json::to_string(&self.data)?;
        Ok(STANDARD.encode(json))
    }

    /// Decode a base64 JSON string into this store.
    ///
    /// Invalid input is ignored and leaves the store unchanged (matching PHP behavior).
    pub fn decode(&mut self, data: &str) -> &mut Self {
        let Ok(decoded) = STANDARD.decode(data) else {
            return self;
        };

        let Ok(json) = serde_json::from_slice::<Value>(&decoded) else {
            return self;
        };

        let Some(object) = json.as_object() else {
            return self;
        };

        for (key, value) in object {
            self.set_property(key.clone(), value.clone());
        }

        self
    }

    /// All stored properties.
    #[must_use]
    pub fn properties(&self) -> &HashMap<String, Value> {
        &self.data
    }
}
