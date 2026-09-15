//! Function resources.

use serde_json::{json, Map, Value};

use crate::resource::{
    Resource, ResourceBase, TYPE_DEPLOYMENT, TYPE_ENVIRONMENT_VARIABLE, TYPE_FUNCTION,
};
use crate::transfer::GROUP_FUNCTIONS;

fn map_str(m: &Map<String, Value>, key: &str) -> String {
    m.get(key).and_then(Value::as_str).unwrap_or("").to_owned()
}

fn map_string_vec(m: &Map<String, Value>, key: &str) -> Vec<String> {
    m.get(key)
        .and_then(Value::as_array)
        .map(|a| {
            a.iter()
                .filter_map(|v| v.as_str().map(ToOwned::to_owned))
                .collect()
        })
        .unwrap_or_default()
}

#[derive(Debug, Clone)]
pub struct Func {
    base: ResourceBase,
    name: String,
    runtime: String,
    execute: Vec<String>,
    enabled: bool,
    events: Vec<String>,
    schedule: String,
    timeout: i64,
    active_deployment: String,
    entrypoint: String,
    commands: String,
    logging: bool,
    scopes: Vec<String>,
    specification: String,
    build_specification: String,
}

impl Func {
    pub fn new(id: impl Into<String>, name: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            name: name.into(),
            runtime: String::new(),
            execute: Vec::new(),
            enabled: true,
            events: Vec::new(),
            schedule: String::new(),
            timeout: 0,
            active_deployment: String::new(),
            entrypoint: String::new(),
            commands: String::new(),
            logging: true,
            scopes: Vec::new(),
            specification: String::new(),
            build_specification: String::new(),
        }
    }

    /// PHP `Func::fromArray`.
    #[must_use]
    pub fn from_array(array: &Map<String, Value>) -> Self {
        let spec = array
            .get("runtimeSpecification")
            .and_then(Value::as_str)
            .or_else(|| array.get("specification").and_then(Value::as_str))
            .unwrap_or("")
            .to_owned();
        let build = array
            .get("buildSpecification")
            .and_then(Value::as_str)
            .or_else(|| array.get("specification").and_then(Value::as_str))
            .unwrap_or("")
            .to_owned();
        let mut func = Self::new(map_str(array, "id"), map_str(array, "name"));
        func.runtime = map_str(array, "runtime");
        func.execute = map_string_vec(array, "execute");
        func.enabled = array
            .get("enabled")
            .and_then(Value::as_bool)
            .unwrap_or(true);
        func.events = map_string_vec(array, "events");
        func.schedule = map_str(array, "schedule");
        func.timeout = array.get("timeout").and_then(Value::as_i64).unwrap_or(0);
        func.active_deployment = map_str(array, "activeDeployment");
        func.entrypoint = map_str(array, "entrypoint");
        func.commands = map_str(array, "commands");
        func.logging = array
            .get("logging")
            .and_then(Value::as_bool)
            .unwrap_or(true);
        func.scopes = map_string_vec(array, "scopes");
        func.specification = spec;
        func.build_specification = build;
        func
    }

    #[must_use]
    pub fn get_function_name(&self) -> &str {
        &self.name
    }
    #[must_use]
    pub fn get_runtime(&self) -> &str {
        &self.runtime
    }
    #[must_use]
    pub fn get_runtime_specification(&self) -> &str {
        &self.specification
    }
    #[must_use]
    pub fn get_build_specification(&self) -> &str {
        if self.build_specification.is_empty() {
            &self.specification
        } else {
            &self.build_specification
        }
    }
    #[must_use]
    pub fn get_specification(&self) -> &str {
        &self.specification
    }
}

impl Resource for Func {
    fn get_name(&self) -> &'static str {
        TYPE_FUNCTION
    }
    fn get_group(&self) -> &'static str {
        GROUP_FUNCTIONS
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
    fn json_serialize(&self) -> Map<String, Value> {
        json!({
            "id": self.get_id(),
            "name": self.name,
            "execute": self.execute,
            "enabled": self.enabled,
            "runtime": self.runtime,
            "events": self.events,
            "schedule": self.schedule,
            "timeout": self.timeout,
            "activeDeployment": self.active_deployment,
            "entrypoint": self.entrypoint,
            "commands": self.commands,
            "logging": self.logging,
            "scopes": self.scopes,
            "specification": self.specification,
            "runtimeSpecification": self.specification,
            "buildSpecification": self.build_specification,
        })
        .as_object()
        .cloned()
        .unwrap_or_default()
    }
}

#[derive(Debug, Clone)]
pub struct Deployment {
    base: ResourceBase,
    function: Func,
    data: String,
    start: i64,
}

impl Deployment {
    pub fn new(id: impl Into<String>, function: Func) -> Self {
        Self {
            base: ResourceBase::new(id),
            function,
            data: String::new(),
            start: 0,
        }
    }
    #[must_use]
    pub fn get_function(&self) -> &Func {
        &self.function
    }
    #[must_use]
    pub fn get_data(&self) -> &str {
        &self.data
    }
    pub fn set_data(&mut self, data: impl Into<String>) {
        self.data = data.into();
    }
    #[must_use]
    pub fn get_start(&self) -> i64 {
        self.start
    }
}

impl Resource for Deployment {
    fn get_name(&self) -> &'static str {
        TYPE_DEPLOYMENT
    }
    fn get_group(&self) -> &'static str {
        GROUP_FUNCTIONS
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}

#[derive(Debug, Clone)]
pub struct EnvVar {
    base: ResourceBase,
}

impl EnvVar {
    pub fn new(id: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
        }
    }
}

impl Resource for EnvVar {
    fn get_name(&self) -> &'static str {
        TYPE_ENVIRONMENT_VARIABLE
    }
    fn get_group(&self) -> &'static str {
        GROUP_FUNCTIONS
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}
