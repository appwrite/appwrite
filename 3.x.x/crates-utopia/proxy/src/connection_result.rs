//! Connection routing result. Mirrors `src/ConnectionResult.php`.

use std::collections::HashMap;

use crate::protocol::Protocol;

/// Immutable routing result: validated endpoint + protocol + metadata.
#[derive(Debug, Clone)]
pub struct ConnectionResult {
    endpoint: String,
    protocol: Protocol,
    metadata: HashMap<String, String>,
}

impl ConnectionResult {
    pub fn new(
        endpoint: impl Into<String>,
        protocol: Protocol,
        metadata: HashMap<String, String>,
    ) -> Self {
        Self {
            endpoint: endpoint.into(),
            protocol,
            metadata,
        }
    }

    pub fn endpoint(&self) -> &str {
        &self.endpoint
    }

    pub fn protocol(&self) -> Protocol {
        self.protocol
    }

    pub fn metadata(&self) -> &HashMap<String, String> {
        &self.metadata
    }
}
