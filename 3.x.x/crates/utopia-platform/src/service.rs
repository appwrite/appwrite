use std::collections::HashMap;

use crate::action::Action;

/// Service runtime kind.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ServiceType {
    Http,
    Task,
    GraphQL,
    Worker,
}

impl ServiceType {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Http => "http",
            Self::Task => "Task",
            Self::GraphQL => "GraphQL",
            Self::Worker => "Worker",
        }
    }

    pub fn parse(value: &str) -> Option<Self> {
        match value {
            "http" => Some(Self::Http),
            "Task" | "task" => Some(Self::Task),
            "GraphQL" | "graphql" => Some(Self::GraphQL),
            "Worker" | "worker" => Some(Self::Worker),
            _ => None,
        }
    }
}

/// Collection of actions exposed by a service.
#[derive(Debug, Clone)]
pub struct Service {
    service_type: ServiceType,
    actions: HashMap<String, Action>,
}

impl Service {
    pub fn new(service_type: ServiceType) -> Self {
        Self {
            service_type,
            actions: HashMap::new(),
        }
    }

    pub fn http() -> Self {
        Self::new(ServiceType::Http)
    }

    pub fn task() -> Self {
        Self::new(ServiceType::Task)
    }

    pub fn graphql() -> Self {
        Self::new(ServiceType::GraphQL)
    }

    pub fn worker() -> Self {
        Self::new(ServiceType::Worker)
    }

    pub fn set_type(mut self, service_type: ServiceType) -> Self {
        self.service_type = service_type;
        self
    }

    pub fn service_type(&self) -> ServiceType {
        self.service_type
    }

    pub fn add_action(mut self, key: impl Into<String>, action: Action) -> Self {
        self.actions.insert(key.into(), action);
        self
    }

    pub fn remove_action(&mut self, key: &str) -> &mut Self {
        self.actions.remove(key);
        self
    }

    pub fn get_action(&self, key: &str) -> Option<&Action> {
        self.actions.get(key)
    }

    pub fn get_actions(&self) -> &HashMap<String, Action> {
        &self.actions
    }
}
