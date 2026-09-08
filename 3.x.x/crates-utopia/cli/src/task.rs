use std::sync::Arc;

use serde_json::Value;
use utopia_servers::{Hook, HookError};
use utopia_validators::Validator;

use crate::params::Params;

/// Action callback. PHP `call_user_func_array($hook->getAction(), $params)`.
pub type ActionFn = Arc<dyn Fn(&Params) -> Value + Send + Sync>;

/// Hook with an action callback (init / shutdown / error / task body).
#[derive(Clone)]
pub struct CliHook {
    meta: Hook,
    action: Option<ActionFn>,
}

impl std::fmt::Debug for CliHook {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("CliHook")
            .field("meta", &self.meta)
            .field("action", &self.action.as_ref().map(|_| "ActionFn"))
            .finish()
    }
}

impl Default for CliHook {
    fn default() -> Self {
        Self::new()
    }
}

impl CliHook {
    pub fn new() -> Self {
        Self {
            meta: Hook::new(),
            action: None,
        }
    }

    pub fn desc(&mut self, desc: impl Into<String>) -> &mut Self {
        self.meta.desc(desc);
        self
    }

    pub fn get_desc(&self) -> &str {
        self.meta.get_desc()
    }

    pub fn groups<I, S>(&mut self, groups: I) -> &mut Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.meta.groups(groups);
        self
    }

    pub fn get_groups(&self) -> &[String] {
        self.meta.get_groups()
    }

    pub fn label(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self {
        self.meta.label(key, value);
        self
    }

    pub fn get_label(&self, key: &str, default: Value) -> Value {
        self.meta.get_label(key, default)
    }

    pub fn inject(&mut self, injection: impl Into<String>) -> Result<&mut Self, HookError> {
        self.meta.inject(injection)?;
        Ok(self)
    }

    pub fn param(
        &mut self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> &mut Self {
        self.meta
            .param(key, default, validator, description, optional);
        self
    }

    #[allow(clippy::too_many_arguments)]
    pub fn param_full(
        &mut self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
        injections: Vec<String>,
        skip_validation: bool,
        deprecated: bool,
        example: impl Into<String>,
        aliases: Vec<String>,
        enum_meta: Option<utopia_servers::EnumMeta>,
    ) -> &mut Self {
        self.meta.param_full(
            key,
            default,
            validator,
            description,
            optional,
            injections,
            skip_validation,
            deprecated,
            example,
            aliases,
            enum_meta,
        );
        self
    }

    pub fn action<F>(&mut self, callback: F) -> &mut Self
    where
        F: Fn(&Params) -> Value + Send + Sync + 'static,
    {
        self.meta.action_marker();
        self.action = Some(Arc::new(callback));
        self
    }

    pub fn get_action(&self) -> Option<&ActionFn> {
        self.action.as_ref()
    }

    pub fn invoke(&self, params: &Params) -> Value {
        match &self.action {
            Some(action) => action(params),
            None => Value::Null,
        }
    }

    pub fn get_params(&self) -> &std::collections::HashMap<String, utopia_servers::ParamDef> {
        self.meta.get_params()
    }

    pub fn get_dependencies(&self) -> Vec<String> {
        self.meta.get_dependencies()
    }

    pub fn meta(&self) -> &Hook {
        &self.meta
    }
}

/// Named CLI task. PHP `Utopia\CLI\Task extends Hook`.
#[derive(Clone, Debug)]
pub struct Task {
    name: String,
    hook: CliHook,
}

impl Task {
    /// PHP `new Task($name)`.
    pub fn new(name: impl Into<String>) -> Self {
        Self {
            name: name.into(),
            hook: CliHook::new(),
        }
    }

    pub fn get_name(&self) -> &str {
        &self.name
    }

    pub fn desc(&mut self, desc: impl Into<String>) -> &mut Self {
        self.hook.desc(desc);
        self
    }

    pub fn get_desc(&self) -> &str {
        self.hook.get_desc()
    }

    pub fn groups<I, S>(&mut self, groups: I) -> &mut Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.hook.groups(groups);
        self
    }

    pub fn get_groups(&self) -> &[String] {
        self.hook.get_groups()
    }

    pub fn label(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self {
        self.hook.label(key, value);
        self
    }

    pub fn get_label(&self, key: &str, default: Value) -> Value {
        self.hook.get_label(key, default)
    }

    pub fn inject(&mut self, injection: impl Into<String>) -> Result<&mut Self, HookError> {
        self.hook.inject(injection)?;
        Ok(self)
    }

    pub fn param(
        &mut self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> &mut Self {
        self.hook
            .param(key, default, validator, description, optional);
        self
    }

    #[allow(clippy::too_many_arguments)]
    pub fn param_full(
        &mut self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
        injections: Vec<String>,
        skip_validation: bool,
        deprecated: bool,
        example: impl Into<String>,
        aliases: Vec<String>,
        enum_meta: Option<utopia_servers::EnumMeta>,
    ) -> &mut Self {
        self.hook.param_full(
            key,
            default,
            validator,
            description,
            optional,
            injections,
            skip_validation,
            deprecated,
            example,
            aliases,
            enum_meta,
        );
        self
    }

    pub fn action<F>(&mut self, callback: F) -> &mut Self
    where
        F: Fn(&Params) -> Value + Send + Sync + 'static,
    {
        self.hook.action(callback);
        self
    }

    pub fn get_action(&self) -> Option<&ActionFn> {
        self.hook.get_action()
    }

    pub fn invoke(&self, params: &Params) -> Value {
        self.hook.invoke(params)
    }

    pub fn get_params(&self) -> &std::collections::HashMap<String, utopia_servers::ParamDef> {
        self.hook.get_params()
    }

    pub fn get_dependencies(&self) -> Vec<String> {
        self.hook.get_dependencies()
    }

    pub fn hook(&self) -> &CliHook {
        &self.hook
    }
}
