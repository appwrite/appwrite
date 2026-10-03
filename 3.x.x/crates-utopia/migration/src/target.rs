use std::collections::HashMap;
use std::sync::{Arc, Mutex};

use crate::cache::Cache;
use crate::exception::Exception;
use crate::warning::Warning;

/// Shared adapter state. PHP `Utopia\Migration\Target`.
#[derive(Default)]
pub struct TargetState {
    pub headers: HashMap<String, String>,
    pub cache: Option<Arc<Mutex<Cache>>>,
    pub errors: Vec<Exception>,
    pub warnings: Vec<Warning>,
    pub endpoint: String,
    pub root_resource_id: String,
    pub root_resource_type: String,
}

impl TargetState {
    #[must_use]
    pub fn new() -> Self {
        let mut headers = HashMap::new();
        headers.insert("Content-Type".into(), String::new());
        Self {
            headers,
            ..Self::default()
        }
    }

    pub fn register_cache(&mut self, cache: Arc<Mutex<Cache>>) {
        self.cache = Some(cache);
    }

    pub fn cache(&self) -> Option<Arc<Mutex<Cache>>> {
        self.cache.clone()
    }

    pub fn add_error(&mut self, error: Exception) {
        self.errors.push(error);
    }

    pub fn add_warning(&mut self, warning: Warning) {
        self.warnings.push(warning);
    }

    pub fn validate_resource_ids(
        &self,
        resource_ids: &HashMap<String, Vec<String>>,
    ) -> Result<(), Exception> {
        for resource_type in resource_ids.keys() {
            if !crate::transfer::ROOT_RESOURCES.contains(&resource_type.as_str()) {
                return Err(Exception::message_only(format!(
                    "Invalid resource type in resourceIds: {resource_type}. Only top-level resources are supported: {}",
                    crate::transfer::ROOT_RESOURCES.join(", ")
                )));
            }
        }
        Ok(())
    }
}

/// PHP `Target` instance methods (name/supported resources are associated on each adapter).
pub trait Target: Send {
    fn state(&self) -> &TargetState;
    fn state_mut(&mut self) -> &mut TargetState;

    fn register_cache(&mut self, cache: Arc<Mutex<Cache>>) {
        self.state_mut().register_cache(cache);
    }

    fn get_errors(&self) -> &[Exception] {
        &self.state().errors
    }

    fn get_warnings(&self) -> &[Warning] {
        &self.state().warnings
    }

    fn add_error(&mut self, error: Exception) {
        self.state_mut().add_error(error);
    }

    fn add_warning(&mut self, warning: Warning) {
        self.state_mut().add_warning(warning);
    }

    fn shutdown(&mut self) {}
    fn success(&mut self) {}
    fn error(&mut self) {}
    fn clean_up(&mut self) {}
}
