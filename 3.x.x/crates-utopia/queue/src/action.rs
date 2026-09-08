use std::collections::HashMap;

use serde_json::Value;
use utopia_di::Container;

use crate::error::QueueError;
use crate::message::Message;

/// Resolved job/hook arguments (PHP `call_user_func_array` list).
#[derive(Clone, Debug)]
pub struct ActionArgs {
    pub(crate) params: HashMap<String, Value>,
    pub(crate) container: Container,
}

impl ActionArgs {
    pub fn param(&self, key: &str) -> Option<&Value> {
        self.params.get(key)
    }

    pub fn params(&self) -> &HashMap<String, Value> {
        &self.params
    }

    pub fn inject<T: std::any::Any + Send + Sync + Clone>(
        &self,
        name: &str,
    ) -> Result<T, QueueError> {
        Ok(self.container.get_as::<T>(name)?)
    }

    pub fn message(&self) -> Result<Message, QueueError> {
        self.inject("message")
    }

    pub fn error(&self) -> Result<QueueError, QueueError> {
        self.inject("error")
    }

    pub fn worker_id(&self) -> Result<String, QueueError> {
        self.inject("workerId")
    }

    pub fn container(&self) -> &Container {
        &self.container
    }
}
