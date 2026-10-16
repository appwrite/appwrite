use std::collections::HashMap;
#[cfg(target_os = "linux")]
use std::io::Write;

use serde_json::Value;
use utopia_di::{Container, Resource};
use utopia_servers::ParamDef;
use utopia_validators::{Validator, ValueType};

use crate::adapter::Adapter;
use crate::adapters::Generic;
use crate::error::CliError;
use crate::params::{ArgValue, BoundArg, Params};
use crate::task::{CliHook, Task};

/// Command-line application. PHP `Utopia\CLI\CLI`.
pub struct Cli {
    adapter: Box<dyn Adapter>,
    command: String,
    container: Container,
    parent_container: Option<Container>,
    args: HashMap<String, ArgValue>,
    tasks: HashMap<String, Task>,
    errors: Vec<CliHook>,
    init: Vec<CliHook>,
    shutdown: Vec<CliHook>,
}

impl std::fmt::Debug for Cli {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Cli")
            .field("adapter", &self.adapter)
            .field("command", &self.command)
            .field("args", &self.args)
            .field("tasks", &self.tasks.keys().collect::<Vec<_>>())
            .field("init", &self.init.len())
            .field("shutdown", &self.shutdown.len())
            .field("errors", &self.errors.len())
            .finish_non_exhaustive()
    }
}

impl Cli {
    /// PHP `new CLI(?Adapter $adapter = null, array $args = [], ?Container $container = null)`.
    ///
    /// Empty `args` reads [`std::env::args`]. Unlike PHP, construction is allowed
    /// outside a "CLI SAPI" - Rust has no equivalent check.
    pub fn new(
        adapter: Option<Box<dyn Adapter>>,
        args: Vec<String>,
        container: Option<Container>,
    ) -> Result<Self, CliError> {
        let args = if args.is_empty() {
            std::env::args().collect()
        } else {
            args
        };

        let mut cli = Self {
            adapter: adapter.unwrap_or_else(|| Box::new(Generic::new())),
            command: String::new(),
            parent_container: container.clone(),
            container: match &container {
                Some(parent) => Container::child(parent),
                None => Container::new(),
            },
            args: HashMap::new(),
            tasks: HashMap::new(),
            errors: Vec::new(),
            init: Vec::new(),
            shutdown: Vec::new(),
        };
        cli.args = cli.parse(args)?;
        set_process_title(&cli.command);
        Ok(cli)
    }

    /// Convenience: Generic adapter, explicit argv (including argv0).
    pub fn with_args(args: Vec<String>) -> Result<Self, CliError> {
        Self::new(Some(Box::new(Generic::new())), args, None)
    }

    pub fn init(&mut self) -> &mut CliHook {
        self.init.push(CliHook::new());
        self.init.last_mut().expect("just pushed")
    }

    pub fn shutdown(&mut self) -> &mut CliHook {
        self.shutdown.push(CliHook::new());
        self.shutdown.last_mut().expect("just pushed")
    }

    pub fn error(&mut self) -> &mut CliHook {
        self.errors.push(CliHook::new());
        self.errors.last_mut().expect("just pushed")
    }

    pub fn task(&mut self, name: impl Into<String>) -> &mut Task {
        let name = name.into();
        self.tasks
            .entry(name.clone())
            .or_insert_with(|| Task::new(name.clone()));
        self.tasks.get_mut(&name).expect("just inserted")
    }

    pub fn get_resource(&self, name: &str) -> Result<Resource, CliError> {
        if !self.container.has(name) {
            return Err(CliError::ResourceNotFound(name.to_string()));
        }
        Ok(self.container.get(name)?)
    }

    pub fn get_resources(&self, list: &[&str]) -> Result<HashMap<String, Resource>, CliError> {
        let mut resources = HashMap::new();
        for name in list {
            resources.insert((*name).to_string(), self.get_resource(name)?);
        }
        Ok(resources)
    }

    /// PHP `setResource($name, $callback)` with no dependencies.
    pub fn set_resource<F>(&self, name: impl Into<String>, callback: F)
    where
        F: Fn() -> Resource + Send + Sync + 'static,
    {
        self.container.set(name, move || Ok(callback()));
    }

    /// PHP `setResource($name, $callback, $dependencies)`.
    pub fn set_resource_with<F>(&self, name: impl Into<String>, dependencies: &[&str], callback: F)
    where
        F: Fn(&[Resource]) -> Resource + Send + Sync + 'static,
    {
        self.container
            .set_with_deps(name, dependencies, move |deps| Ok(callback(deps)));
    }

    pub fn get_container(&self) -> &Container {
        &self.container
    }

    /// PHP `parse(array $args)` - drops argv0, sets [`Self::command`], returns flags.
    pub fn parse(&mut self, mut args: Vec<String>) -> Result<HashMap<String, ArgValue>, CliError> {
        if args.is_empty() {
            return Err(CliError::MissingCommand);
        }
        args.remove(0);

        if args.is_empty() {
            return Err(CliError::MissingCommand);
        }
        self.command = args.remove(0);

        let mut grouped: HashMap<String, Vec<String>> = HashMap::new();
        for arg in args {
            let stripped = arg.strip_prefix("--").unwrap_or(arg.as_str());
            let mut pair = stripped.splitn(2, '=');
            let key = pair.next().unwrap_or("").to_string();
            let value = pair.next().unwrap_or("").to_string();
            grouped.entry(key).or_default().push(value);
        }

        let mut output = HashMap::new();
        for (key, values) in grouped {
            if values.len() == 1 {
                output.insert(
                    key,
                    ArgValue::String(values.into_iter().next().expect("len 1")),
                );
            } else {
                output.insert(key, ArgValue::List(values));
            }
        }
        Ok(output)
    }

    /// PHP `match()`.
    pub fn match_task(&self) -> Option<&Task> {
        self.tasks.get(&self.command)
    }

    pub fn command(&self) -> &str {
        &self.command
    }

    fn get_params(&self, hook: &CliHook) -> Result<Params, CliError> {
        let mut params = Params::new();

        for (key, param) in hook.get_params() {
            let mut value = param.default.clone();
            if let Some(arg) = self.args.get(key) {
                value = arg.as_value();
            } else {
                for alias in &param.aliases {
                    if let Some(arg) = self.args.get(alias) {
                        value = arg.as_value();
                        break;
                    }
                }
            }
            self.validate(key, param, &value)?;
            let coerced = coerce(param.validator.as_ref(), value);
            params.insert(camel_case_it(key), BoundArg::Param(coerced));
        }

        for dependency in hook.get_dependencies() {
            let camel = camel_case_it(&dependency);
            if params.contains_key(&camel) {
                continue;
            }
            params.insert(camel, BoundArg::Inject(self.get_resource(&dependency)?));
        }

        Ok(params)
    }

    /// PHP `run()`. Task failures are routed to error hooks (or swallowed).
    pub fn run(&mut self) -> &mut Self {
        let mut adapter = std::mem::replace(&mut self.adapter, Box::new(Generic::new()));
        adapter.start(&mut || self.dispatch());
        self.adapter = adapter;
        self
    }

    fn dispatch(&mut self) {
        let command = self.command.clone();
        let result = (|| -> Result<(), CliError> {
            let Some(task) = self.tasks.get(&command).cloned() else {
                return Err(CliError::NoCommandFound);
            };

            for hook in self.init.clone() {
                let params = self.get_params(&hook)?;
                hook.invoke(&params);
            }

            let params = self.get_params(task.hook())?;
            task.invoke(&params);

            for hook in self.shutdown.clone() {
                let params = self.get_params(&hook)?;
                hook.invoke(&params);
            }
            Ok(())
        })();

        if let Err(err) = result {
            for hook in self.errors.clone() {
                let captured = err.clone();
                self.set_resource("error", move || Resource::new(captured.clone()));
                if let Ok(params) = self.get_params(&hook) {
                    hook.invoke(&params);
                }
            }
        }
    }

    pub fn get_tasks(&self) -> &HashMap<String, Task> {
        &self.tasks
    }

    pub fn get_args(&self) -> &HashMap<String, ArgValue> {
        &self.args
    }

    fn validate(&self, key: &str, param: &ParamDef, value: &Value) -> Result<(), CliError> {
        if !is_empty_string(value) {
            if !param.validator.is_valid(value) {
                return Err(CliError::InvalidParam {
                    key: key.to_string(),
                    description: param.validator.description(),
                });
            }
        } else if !param.optional {
            return Err(CliError::ParamRequired {
                key: key.to_string(),
            });
        }
        Ok(())
    }

    pub fn set_container(&mut self, container: Container) -> &mut Self {
        self.parent_container = Some(container.clone());
        self.container = Container::child(&container);
        self
    }

    pub fn reset(&mut self) {
        self.container = match &self.parent_container {
            Some(parent) => Container::child(parent),
            None => Container::new(),
        };
    }

    pub fn adapter(&self) -> &dyn Adapter {
        self.adapter.as_ref()
    }
}

/// PHP `'' !== $value` - only the empty string bypasses validation.
fn is_empty_string(value: &Value) -> bool {
    matches!(value, Value::String(s) if s.is_empty())
}

/// PHP `FILTER_VALIDATE_BOOLEAN | FILTER_NULL_ON_FAILURE` for `Boolean` params.
fn coerce(validator: &dyn Validator, value: Value) -> Value {
    let Value::String(ref raw) = value else {
        return value;
    };
    if raw.is_empty() {
        return value;
    }
    if validator.value_type() != ValueType::Boolean {
        return value;
    }
    match filter_var_boolean(raw) {
        Some(flag) => Value::Bool(flag),
        None => value,
    }
}

fn filter_var_boolean(raw: &str) -> Option<bool> {
    match raw.to_ascii_lowercase().as_str() {
        "1" | "true" | "on" | "yes" => Some(true),
        "0" | "false" | "off" | "no" => Some(false),
        _ => None,
    }
}

/// PHP `camelCaseIt`: `-` → `_`, `ucwords(..., '_')`, strip `_`, `lcfirst`.
pub fn camel_case_it(key: &str) -> String {
    let key = key.replace('-', "_");
    let mut titled = String::new();
    let mut cap_next = true;
    for ch in key.chars() {
        if ch == '_' {
            titled.push('_');
            cap_next = true;
        } else if cap_next {
            for upper in ch.to_uppercase() {
                titled.push(upper);
            }
            cap_next = false;
        } else {
            titled.push(ch);
        }
    }
    let stripped: String = titled.chars().filter(|c| *c != '_').collect();
    let mut chars = stripped.chars();
    match chars.next() {
        None => String::new(),
        Some(first) => first.to_lowercase().collect::<String>() + chars.as_str(),
    }
}

fn set_process_title(title: &str) {
    #[cfg(target_os = "linux")]
    {
        let truncated = if title.len() > 15 {
            &title[..15]
        } else {
            title
        };
        let _ = std::fs::OpenOptions::new()
            .write(true)
            .open("/proc/self/comm")
            .and_then(|mut f| f.write_all(truncated.as_bytes()));
    }
    #[cfg(not(target_os = "linux"))]
    {
        let _ = title;
    }
}
