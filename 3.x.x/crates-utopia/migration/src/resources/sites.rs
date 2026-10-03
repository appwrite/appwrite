//! Site resources.

use serde_json::{json, Map, Value};

use crate::resource::{
    Resource, ResourceBase, TYPE_SITE, TYPE_SITE_DEPLOYMENT, TYPE_SITE_VARIABLE,
};
use crate::transfer::GROUP_SITES;

fn map_str(m: &Map<String, Value>, key: &str) -> String {
    m.get(key).and_then(Value::as_str).unwrap_or("").to_owned()
}

#[derive(Debug, Clone)]
pub struct Site {
    base: ResourceBase,
    name: String,
    framework: String,
    build_runtime: String,
    enabled: bool,
    logging: bool,
    timeout: i64,
    install_command: String,
    build_command: String,
    output_directory: String,
    adapter: String,
    fallback_file: String,
    specification: String,
    active_deployment: String,
    build_specification: String,
}

impl Site {
    pub fn new(id: impl Into<String>, name: impl Into<String>) -> Self {
        Self {
            base: ResourceBase::new(id),
            name: name.into(),
            framework: String::new(),
            build_runtime: String::new(),
            enabled: true,
            logging: true,
            timeout: 600,
            install_command: String::new(),
            build_command: String::new(),
            output_directory: String::new(),
            adapter: "static".into(),
            fallback_file: String::new(),
            specification: String::new(),
            active_deployment: String::new(),
            build_specification: String::new(),
        }
    }

    /// PHP `Site::fromArray`.
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
        let mut site = Self::new(map_str(array, "id"), map_str(array, "name"));
        site.framework = map_str(array, "framework");
        site.build_runtime = map_str(array, "buildRuntime");
        site.enabled = array
            .get("enabled")
            .and_then(Value::as_bool)
            .unwrap_or(true);
        site.logging = array
            .get("logging")
            .and_then(Value::as_bool)
            .unwrap_or(true);
        site.timeout = array.get("timeout").and_then(Value::as_i64).unwrap_or(600);
        site.install_command = map_str(array, "installCommand");
        site.build_command = map_str(array, "buildCommand");
        site.output_directory = map_str(array, "outputDirectory");
        array
            .get("adapter")
            .and_then(Value::as_str)
            .unwrap_or("static")
            .clone_into(&mut site.adapter);
        site.fallback_file = map_str(array, "fallbackFile");
        site.specification = spec;
        site.active_deployment = map_str(array, "activeDeployment");
        site.build_specification = build;
        site
    }

    #[must_use]
    pub fn get_site_name(&self) -> &str {
        &self.name
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
}

impl Resource for Site {
    fn get_name(&self) -> &'static str {
        TYPE_SITE
    }
    fn get_group(&self) -> &'static str {
        GROUP_SITES
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
            "framework": self.framework,
            "buildRuntime": self.build_runtime,
            "enabled": self.enabled,
            "logging": self.logging,
            "timeout": self.timeout,
            "installCommand": self.install_command,
            "buildCommand": self.build_command,
            "outputDirectory": self.output_directory,
            "adapter": self.adapter,
            "fallbackFile": self.fallback_file,
            "specification": self.specification,
            "runtimeSpecification": self.specification,
            "activeDeployment": self.active_deployment,
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
    site: Site,
    data: String,
}

impl Deployment {
    pub fn new(id: impl Into<String>, site: Site) -> Self {
        Self {
            base: ResourceBase::new(id),
            site,
            data: String::new(),
        }
    }
    #[must_use]
    pub fn get_site(&self) -> &Site {
        &self.site
    }
    #[must_use]
    pub fn get_data(&self) -> &str {
        &self.data
    }
    pub fn set_data(&mut self, data: impl Into<String>) {
        self.data = data.into();
    }
}

impl Resource for Deployment {
    fn get_name(&self) -> &'static str {
        TYPE_SITE_DEPLOYMENT
    }
    fn get_group(&self) -> &'static str {
        GROUP_SITES
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
        TYPE_SITE_VARIABLE
    }
    fn get_group(&self) -> &'static str {
        GROUP_SITES
    }
    fn base(&self) -> &ResourceBase {
        &self.base
    }
    fn base_mut(&mut self) -> &mut ResourceBase {
        &mut self.base
    }
}
