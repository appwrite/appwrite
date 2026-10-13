//! Functions, their variables and deployments.

use std::sync::{Mutex, MutexGuard};

use php_std::{Array, Value};

use super::{Input, param, to_array, to_bool, to_bytes, to_int, to_string};
use crate::error::Result;
use crate::resource::{Chunk, Kind, Resource, ResourceRef, lock};

/// A function.
///
/// PHP: `Utopia\Migration\Resources\Functions\Func` (`getFunctionName()` is the name).
#[derive(Debug, Clone, PartialEq)]
pub struct Func {
    /// The name.
    pub name: String,
    /// The runtime.
    pub runtime: String,
    /// Roles allowed to execute.
    pub execute: Array,
    /// Whether it is enabled.
    pub enabled: bool,
    /// Events that trigger it.
    pub events: Array,
    /// Its CRON schedule.
    pub schedule: String,
    /// Its timeout in seconds.
    pub timeout: i64,
    /// The active deployment id.
    pub active_deployment: String,
    /// The entrypoint.
    pub entrypoint: String,
    /// The build commands.
    pub commands: String,
    /// Whether executions are logged.
    pub logging: bool,
    /// The API scopes of its key.
    pub scopes: Array,
    /// The runtime specification (`getSpecification()`, `getRuntimeSpecification()`).
    pub specification: String,
    /// The build specification as given.
    pub build_specification: String,
    /// Minutes between scheduled runs (0: none).
    pub interval: i64,
    /// Days inactive deployments are kept (0: forever).
    pub deployment_retention: i64,
}

impl Default for Func {
    fn default() -> Self {
        Self {
            name: String::new(),
            runtime: String::new(),
            execute: Array::new(),
            enabled: true,
            events: Array::new(),
            schedule: String::new(),
            timeout: 0,
            active_deployment: String::new(),
            entrypoint: String::new(),
            commands: String::new(),
            logging: true,
            scopes: Array::new(),
            specification: String::new(),
            build_specification: String::new(),
            interval: 0,
            deployment_retention: 0,
        }
    }
}

impl Func {
    /// A resource of this function.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::Function(self), id)
    }

    /// The build specification, falling back to the runtime one.
    ///
    /// PHP: `getBuildSpecification()`.
    pub fn effective_build_specification(&self) -> &str {
        if self.build_specification.is_empty() { &self.specification } else { &self.build_specification }
    }

    /// PHP: `Func::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Functions\\Func::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let spec = |a: &str, b: &str| i.get(a).or_else(|| i.get(b)).cloned().unwrap_or_else(|| Value::from(""));
        Ok(Func {
            name: to_string(&i.raw("name"), &p(2, "name"))?,
            runtime: to_string(&i.raw("runtime"), &p(3, "runtime"))?,
            execute: to_array(&i.or("execute", Value::Array(Array::new())), &p(4, "execute"))?,
            enabled: to_bool(&i.or("enabled", Value::Bool(true)), &p(5, "enabled"))?,
            events: to_array(&i.or("events", Value::Array(Array::new())), &p(6, "events"))?,
            schedule: to_string(&i.or("schedule", Value::from("")), &p(7, "schedule"))?,
            timeout: to_int(&i.or("timeout", Value::Int(0)), &p(8, "timeout"))?,
            active_deployment: to_string(&i.or("activeDeployment", Value::from("")), &p(9, "activeDeployment"))?,
            entrypoint: to_string(&i.or("entrypoint", Value::from("")), &p(10, "entrypoint"))?,
            commands: to_string(&i.or("commands", Value::from("")), &p(11, "commands"))?,
            logging: to_bool(&i.or("logging", Value::Bool(true)), &p(12, "logging"))?,
            scopes: to_array(&i.or("scopes", Value::Array(Array::new())), &p(13, "scopes"))?,
            specification: to_string(&spec("runtimeSpecification", "specification"), &p(14, "specification"))?,
            build_specification: to_string(&spec("buildSpecification", "specification"), &p(15, "buildSpecification"))?,
            interval: to_int(&i.or("interval", Value::Int(0)), &p(16, "interval"))?,
            deployment_retention: to_int(&i.or("deploymentRetention", Value::Int(0)), &p(17, "deploymentRetention"))?,
        }
        .resource(id))
    }
}

/// A function variable.
///
/// PHP: `Utopia\Migration\Resources\Functions\EnvVar`.
#[derive(Debug)]
pub struct EnvVar {
    /// The function (`getFunc()`).
    pub func: ResourceRef,
    /// The key.
    pub key: String,
    /// The value.
    pub value: String,
    /// Whether it is secret (`isSecret()`): readable only by the runtime.
    pub secret: bool,
}

impl EnvVar {
    /// A resource of this variable.
    pub fn resource(self, id: impl Into<String>) -> ResourceRef {
        Resource::with_id(Kind::EnvVar(self), id)
    }

    /// PHP: `EnvVar::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Functions\\EnvVar::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let func = Func::from_array(
            &i.array("func", &param("Utopia\\Migration\\Resources\\Functions\\Func::fromArray", 1, "array"))?,
        )?;
        Ok(EnvVar {
            func,
            key: to_string(&i.raw("key"), &p(3, "key"))?,
            value: to_string(&i.raw("value"), &p(4, "value"))?,
            secret: super::cast_bool(&i.or("secret", Value::Bool(false))),
        }
        .resource(id))
    }
}

/// A function deployment, transferred in chunks.
///
/// PHP: `Utopia\Migration\Resources\Functions\Deployment`.
#[derive(Debug)]
pub struct Deployment {
    /// The function (`getFunction()`).
    pub func: ResourceRef,
    /// The size of the code archive in bytes.
    pub size: i64,
    /// The entrypoint.
    pub entrypoint: String,
    /// Whether the deployment is the active one.
    pub activated: bool,
    chunk: Mutex<Chunk>,
}

impl Deployment {
    /// A deployment of `func` with its first chunk.
    pub fn new(func: ResourceRef, size: i64, entrypoint: impl Into<String>, chunk: Chunk, activated: bool) -> Self {
        Self { func, size, entrypoint: entrypoint.into(), activated, chunk: Mutex::new(chunk) }
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
        Resource::with_id(Kind::Deployment(self), id)
    }

    /// PHP: `Deployment::fromArray()`.
    pub fn from_array(array: &Array) -> Result<ResourceRef> {
        let i = Input::new(array);
        let p = |n, name| param("Utopia\\Migration\\Resources\\Functions\\Deployment::__construct", n, name);
        let id = to_string(&i.raw("id"), &p(1, "id"))?;
        let func = Func::from_array(
            &i.array("func", &param("Utopia\\Migration\\Resources\\Functions\\Func::fromArray", 1, "array"))?,
        )?;
        let size = to_int(&i.raw("size"), &p(3, "size"))?;
        let entrypoint = to_string(&i.raw("entrypoint"), &p(4, "entrypoint"))?;
        let start = to_int(&i.or("start", Value::Int(0)), &p(5, "start"))?;
        let end = to_int(&i.or("end", Value::Int(0)), &p(6, "end"))?;
        let data = to_bytes(&i.or("data", Value::from("")), &p(7, "data"))?;
        let activated = to_bool(&i.or("activated", Value::Bool(false)), &p(8, "activated"))?;
        Ok(Deployment::new(func, size, entrypoint, Chunk { start, end, data }, activated).resource(id))
    }
}
