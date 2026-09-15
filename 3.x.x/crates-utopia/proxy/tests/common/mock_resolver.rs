//! In-memory resolver used by tests. Mirrors `tests/MockResolver.php`.

use std::collections::HashMap;
use std::sync::Mutex;

use async_trait::async_trait;
use utopia_proxy::{Resolver, ResolverError, ResolverResult};

/// Mock resolver: either returns a preconfigured endpoint (echoing the input
/// back as `metadata["data"]`), or throws a preconfigured exception.
pub struct MockResolver {
    state: Mutex<State>,
}

#[derive(Default)]
struct State {
    endpoint: Option<String>,
    exception: Option<ResolverError>,
}

impl MockResolver {
    pub fn new() -> Self {
        Self {
            state: Mutex::new(State::default()),
        }
    }

    pub fn set_endpoint(&self, endpoint: impl Into<String>) -> &Self {
        let mut state = self.state.lock().unwrap();
        state.endpoint = Some(endpoint.into());
        state.exception = None;
        self
    }

    pub fn set_exception(&self, exception: ResolverError) -> &Self {
        let mut state = self.state.lock().unwrap();
        state.exception = Some(exception);
        state.endpoint = None;
        self
    }
}

impl Default for MockResolver {
    fn default() -> Self {
        Self::new()
    }
}

fn clone_error(error: &ResolverError) -> ResolverError {
    let context = error.context().clone();
    let base = match error {
        ResolverError::NotFound { message, .. } => ResolverError::not_found(message.clone()),
        ResolverError::Unavailable { message, .. } => ResolverError::unavailable(message.clone()),
        ResolverError::Timeout { message, .. } => ResolverError::timeout(message.clone()),
        ResolverError::Forbidden { message, .. } => ResolverError::forbidden(message.clone()),
        ResolverError::Internal { message, .. } => ResolverError::internal(message.clone()),
    };
    base.with_context(context)
}

#[async_trait]
impl Resolver for MockResolver {
    async fn resolve(&self, data: &[u8]) -> Result<ResolverResult, ResolverError> {
        let state = self.state.lock().unwrap();
        if let Some(exception) = &state.exception {
            return Err(clone_error(exception));
        }
        let Some(endpoint) = &state.endpoint else {
            return Err(ResolverError::not_found("No endpoint configured"));
        };
        let mut metadata = HashMap::new();
        metadata.insert(
            "data".to_string(),
            String::from_utf8_lossy(data).to_string(),
        );
        Ok(ResolverResult::new(endpoint.clone()).with_metadata(metadata))
    }
}
