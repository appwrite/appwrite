use std::collections::HashMap;

use crate::error::{PlatformError, Result};
use crate::service::{Service, ServiceType};

/// Groups services by runtime type.
#[derive(Debug, Default, Clone)]
pub struct Module {
    all: HashMap<String, Service>,
    by_type: HashMap<ServiceType, HashMap<String, Service>>,
}

impl Module {
    pub fn new() -> Self {
        let mut by_type = HashMap::new();
        by_type.insert(ServiceType::Http, HashMap::new());
        by_type.insert(ServiceType::Task, HashMap::new());
        by_type.insert(ServiceType::GraphQL, HashMap::new());
        by_type.insert(ServiceType::Worker, HashMap::new());
        Self {
            all: HashMap::new(),
            by_type,
        }
    }

    pub fn add_service(mut self, key: impl Into<String>, service: Service) -> Self {
        let key = key.into();
        let service_type = service.service_type();
        self.all.insert(key.clone(), service.clone());
        self.by_type
            .entry(service_type)
            .or_default()
            .insert(key, service);
        self
    }

    pub fn remove_service(&mut self, key: &str) -> &mut Self {
        if let Some(service) = self.all.remove(key) {
            if let Some(bucket) = self.by_type.get_mut(&service.service_type()) {
                bucket.remove(key);
            }
        }
        self
    }

    pub fn get_service(&self, key: &str) -> Result<&Service> {
        self.all
            .get(key)
            .ok_or_else(|| PlatformError::ServiceNotFound(key.to_string()))
    }

    pub fn get_services(&self) -> &HashMap<String, Service> {
        &self.all
    }

    pub fn get_services_by_type(&self, service_type: ServiceType) -> &HashMap<String, Service> {
        self.by_type
            .get(&service_type)
            .expect("service bucket always initialized")
    }
}
