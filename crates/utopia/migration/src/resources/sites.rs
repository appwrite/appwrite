//! Sites, their variables and deployments.

use std::sync::{Mutex, MutexGuard};

use php_std::{Array, Value};

use super::{Input, param, to_bool, to_bytes, to_int, to_string};
use crate::error::Result;
use crate::resource::{Chunk, Kind, Resource, ResourceRef, lock};

/// A site.
///
/// PHP: `Utopia\Migration\Resources\Sites\Site` (`getSiteName()` is the name).
#[derive(Debug, Clone, PartialEq)]
pub struct Site {
    /// The name.
    pub name: String,
    /// The framework.
    pub framework: String,
    /// The build runtime.
    pub build_runtime: String,
    /// Whether it is enabled.
    pub enabled: bool,
    /// Whether requests are logged.
    pub logging: bool,
    /// The SSR timeout in seconds.
    pub timeout: i64,
    /// The install command.
    pub install_command: String,
    /// The build command.
    pub build_command: String,
    /// The output directory.
    pub output_directory: String,
    /// `static` or `ssr`.
    pub adapter: String,
    /// The fallback file of a static site.
    pub fallback_file: String,
    /// The runtime specification.
    pub specification: String,
    /// The active deployment id.
    pub active_deployment: String,
    /// The build specification as given.
    pub build_specification: String,
}

impl Default for Site {
    fn default() -> Self {
        Self {
            name: String::new(),
            framework: String::new(),
            build_runtime: String::new(),
            enabled: true,
            logging: true,
            timeout: 600,
            install_command: String::new(),
            build_command: String::new(),
            output_directory: String::new(),
            adapter: "static".to_owned(),
            fallback_file: String::new(),
            specification: String::new(),
            active_deployment: String::new(),
            build_specification: String::new(),
        }
    }
}

impl Site {
    /// A resource of this site.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::Site(self), id)
    }

    /// The build specification, falling back to the runtime one.
    ///
    /// PHP: `getBuildSpecification()`.
    pub fn effective_build_specification(&self) -> &str {
        if self.build_specification.is_empty() { &self.specification } else { &self.build_specification }
    }

    /// PHP: `Site::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Sites\\Site::__construct", n, name);
        let s = |k: &str, d: &str, n, name| to_string(&i.or(k, Value::from(d)), &p(n, name));
        let spec = |a: &str, b: &str| i.get(a).or_else(|| i.get(b)).cloned().unwrap_or_else(|| Value::from(""));
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        Ok(Site {
            name: to_string(&i.raw("name"), &p(2, "name"))?,
            framework: to_string(&i.raw("framework"), &p(3, "framework"))?,
            build_runtime: to_string(&i.raw("buildRuntime"), &p(4, "buildRuntime"))?,
            enabled: to_bool(&i.or("enabled", Value::Bool(true)), &p(5, "enabled"))?,
            logging: to_bool(&i.or("logging", Value::Bool(true)), &p(6, "logging"))?,
            timeout: to_int(&i.or("timeout", Value::Int(600)), &p(7, "timeout"))?,
            install_command: s("installCommand", "", 8, "installCommand")?,
            build_command: s("buildCommand", "", 9, "buildCommand")?,
            output_directory: s("outputDirectory", "", 10, "outputDirectory")?,
            adapter: s("adapter", "static", 11, "adapter")?,
            fallback_file: s("fallbackFile", "", 12, "fallbackFile")?,
            specification: to_string(&spec("runtimeSpecification", "specification"), &p(13, "specification"))?,
            active_deployment: s("activeDeployment", "", 14, "activeDeployment")?,
            build_specification: to_string(&spec("buildSpecification", "specification"), &p(15, "buildSpecification"))?,
        }
        .resource(id))
    }
}

/// A site variable.
///
/// PHP: `Utopia\Migration\Resources\Sites\EnvVar`.
#[derive(Debug)]
pub struct SiteEnvVar {
    /// The site.
    pub site: ResourceRef,
    /// The key.
    pub key: String,
    /// The value.
    pub value: String,
}

impl SiteEnvVar {
    /// A resource of this variable.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::SiteEnvVar(self), id)
    }

    /// PHP: `EnvVar::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Sites\\EnvVar::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let site = Site::from_array(
            &i.array("site", &param("Utopia\\Migration\\Resources\\Sites\\Site::fromArray", 1, "array"))?,
        )?;
        Ok(SiteEnvVar {
            site,
            key: to_string(&i.raw("key"), &p(3, "key"))?,
            value: to_string(&i.raw("value"), &p(4, "value"))?,
        }
        .resource(id))
    }
}

/// A site deployment, transferred in chunks.
///
/// PHP: `Utopia\Migration\Resources\Sites\Deployment`.
#[derive(Debug)]
pub struct SiteDeployment {
    /// The site.
    pub site: ResourceRef,
    /// The size of the archive in bytes.
    pub size: i64,
    /// Whether the deployment is the active one.
    pub activated: bool,
    chunk: Mutex<Chunk>,
}

impl SiteDeployment {
    /// A deployment of `site` with its first chunk.
    pub fn new(site: ResourceRef, size: i64, chunk: Chunk, activated: bool) -> Self {
        Self { site, size, activated, chunk: Mutex::new(chunk) }
    }

    /// The current chunk.
    pub fn chunk(&self) -> MutexGuard<'_, Chunk> {
        lock(&self.chunk)
    }

    /// The current chunk, to change it.
    pub fn chunk_mut(&self) -> MutexGuard<'_, Chunk> {
        lock(&self.chunk)
    }

    /// A resource of this deployment.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::SiteDeployment(self), id)
    }

    /// PHP: `Deployment::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Sites\\Deployment::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let site = Site::from_array(
            &i.array("site", &param("Utopia\\Migration\\Resources\\Sites\\Site::fromArray", 1, "array"))?,
        )?;
        let size = to_int(&i.raw("size"), &p(3, "size"))?;
        let start = to_int(&i.or("start", Value::Int(0)), &p(4, "start"))?;
        let end = to_int(&i.or("end", Value::Int(0)), &p(5, "end"))?;
        let data = to_bytes(&i.or("data", Value::from("")), &p(6, "data"))?;
        let activated = to_bool(&i.or("activated", Value::Bool(false)), &p(7, "activated"))?;
        Ok(SiteDeployment::new(site, size, Chunk { start, end, data }, activated).resource(id))
    }
}
