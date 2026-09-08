//! Fixed resolver - always returns the same endpoint. Mirrors `src/Resolver/Fixed.php`.

use async_trait::async_trait;

use super::{Resolver, ResolverError, ResolverResult};

/// Resolver that always returns the same configured endpoint.
#[derive(Debug, Clone)]
pub struct Fixed {
    pub endpoint: String,
}

impl Fixed {
    pub fn new(endpoint: impl Into<String>) -> Self {
        Self {
            endpoint: endpoint.into(),
        }
    }
}

#[async_trait]
impl Resolver for Fixed {
    async fn resolve(&self, _data: &[u8]) -> Result<ResolverResult, ResolverError> {
        Ok(ResolverResult::new(self.endpoint.clone()))
    }
}
