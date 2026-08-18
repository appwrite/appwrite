use std::collections::HashMap;
#[cfg(feature = "http")]
use std::future::Future;
#[cfg(feature = "http")]
use std::pin::Pin;
use std::sync::Arc;

use serde_json::Value;
use utopia_validators::Validator;

use crate::enum_type::Enum;
use crate::error::{PlatformError, Result};

/// Action lifecycle / hook kind.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Default)]
pub enum ActionType {
    #[default]
    Default,
    Init,
    Error,
    Options,
    Shutdown,
    WorkerStart,
    WorkerStop,
}

/// HTTP request methods supported by platform actions.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum HttpMethod {
    Get,
    Post,
    Put,
    Patch,
    Delete,
    Options,
    Head,
}

impl HttpMethod {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Get => "GET",
            Self::Post => "POST",
            Self::Put => "PUT",
            Self::Patch => "PATCH",
            Self::Delete => "DELETE",
            Self::Options => "OPTIONS",
            Self::Head => "HEAD",
        }
    }
}

/// Parameter definition attached to an action.
#[derive(Clone)]
pub struct ParamDef {
    pub default: Value,
    pub validator: Arc<dyn Validator>,
    pub description: String,
    pub optional: bool,
    pub injections: Vec<String>,
    pub skip_validation: bool,
    pub deprecated: bool,
    pub example: String,
    pub aliases: Vec<String>,
    pub enum_meta: Option<Enum>,
}

impl std::fmt::Debug for ParamDef {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ParamDef")
            .field("default", &self.default)
            .field("description", &self.description)
            .field("optional", &self.optional)
            .field("deprecated", &self.deprecated)
            .field("aliases", &self.aliases)
            .field("enum_meta", &self.enum_meta)
            .finish_non_exhaustive()
    }
}

#[derive(Debug, Clone)]
pub enum ActionOption {
    Param { key: String, def: ParamDef },
    Injection { name: String },
}

/// Sync callback used by task/worker actions in the MVP.
pub type SyncCallback = Arc<dyn Fn() + Send + Sync>;

#[cfg(feature = "http")]
pub type HttpActionFuture = Pin<Box<dyn Future<Output = utopia_http::Result<()>> + Send + 'static>>;

#[cfg(feature = "http")]
pub type HttpActionCallback =
    Arc<dyn Fn(utopia_http::ActionContext) -> HttpActionFuture + Send + Sync>;

#[cfg(feature = "cli")]
pub type CliActionCallback = utopia_cli::ActionFn;

/// Fluent builder for a platform action.
#[derive(Clone)]
pub struct Action {
    action_type: ActionType,
    desc: Option<String>,
    groups: Vec<String>,
    labels: HashMap<String, Value>,
    params: HashMap<String, ParamDef>,
    injections: Vec<String>,
    options: HashMap<String, ActionOption>,
    http_methods: Vec<String>,
    http_path: Option<String>,
    http_aliases: Vec<String>,
    sync_callback: Option<SyncCallback>,
    #[cfg(feature = "http")]
    http_callback: Option<HttpActionCallback>,
    #[cfg(feature = "cli")]
    cli_callback: Option<CliActionCallback>,
}

impl std::fmt::Debug for Action {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Action")
            .field("action_type", &self.action_type)
            .field("desc", &self.desc)
            .field("groups", &self.groups)
            .field("labels", &self.labels)
            .field("params", &self.params)
            .field("injections", &self.injections)
            .field("http_methods", &self.http_methods)
            .field("http_path", &self.http_path)
            .field("http_aliases", &self.http_aliases)
            .field("has_sync_callback", &self.sync_callback.is_some())
            .finish_non_exhaustive()
    }
}

impl Default for Action {
    fn default() -> Self {
        Self::new()
    }
}

impl Action {
    pub fn new() -> Self {
        Self {
            action_type: ActionType::Default,
            desc: None,
            groups: Vec::new(),
            labels: HashMap::new(),
            params: HashMap::new(),
            injections: Vec::new(),
            options: HashMap::new(),
            http_methods: Vec::new(),
            http_path: None,
            http_aliases: Vec::new(),
            sync_callback: None,
            #[cfg(feature = "http")]
            http_callback: None,
            #[cfg(feature = "cli")]
            cli_callback: None,
        }
    }

    pub fn action_type(&self) -> ActionType {
        self.action_type
    }

    pub fn set_type(mut self, action_type: ActionType) -> Self {
        self.action_type = action_type;
        self
    }

    pub fn desc(mut self, description: impl Into<String>) -> Self {
        self.desc = Some(description.into());
        self
    }

    pub fn get_desc(&self) -> Option<&str> {
        self.desc.as_deref()
    }

    pub fn groups<I, S>(mut self, groups: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.groups = groups.into_iter().map(Into::into).collect();
        self
    }

    pub fn get_groups(&self) -> &[String] {
        &self.groups
    }

    pub fn label(mut self, key: impl Into<String>, value: impl Into<Value>) -> Self {
        self.labels.insert(key.into(), value.into());
        self
    }

    pub fn get_labels(&self) -> &HashMap<String, Value> {
        &self.labels
    }

    pub fn sync_callback(mut self, callback: SyncCallback) -> Self {
        self.sync_callback = Some(callback);
        self
    }

    pub fn callback<F>(self, callback: F) -> Self
    where
        F: Fn() + Send + Sync + 'static,
    {
        self.sync_callback(Arc::new(callback))
    }

    #[cfg(feature = "http")]
    pub fn http_callback(mut self, callback: HttpActionCallback) -> Self {
        self.http_callback = Some(callback);
        self
    }

    #[cfg(feature = "http")]
    pub fn http_action<F, Fut>(self, f: F) -> Self
    where
        F: Fn(utopia_http::ActionContext) -> Fut + Send + Sync + 'static,
        Fut: Future<Output = utopia_http::Result<()>> + Send + 'static,
    {
        self.http_callback(Arc::new(move |ctx| Box::pin(f(ctx))))
    }

    pub fn get_sync_callback(&self) -> Option<&SyncCallback> {
        self.sync_callback.as_ref()
    }

    #[cfg(feature = "http")]
    pub fn get_http_callback(&self) -> Option<&HttpActionCallback> {
        self.http_callback.as_ref()
    }

    #[cfg(feature = "cli")]
    pub fn cli_callback(mut self, callback: CliActionCallback) -> Self {
        self.cli_callback = Some(callback);
        self
    }

    /// CLI handler. Receives parsed [`utopia_cli::Params`] (camelCased keys) and returns a JSON value.
    #[cfg(feature = "cli")]
    pub fn cli_action<F>(self, f: F) -> Self
    where
        F: Fn(&utopia_cli::Params) -> Value + Send + Sync + 'static,
    {
        self.cli_callback(Arc::new(f))
    }

    #[cfg(feature = "cli")]
    pub fn get_cli_callback(&self) -> Option<&CliActionCallback> {
        self.cli_callback.as_ref()
    }

    #[allow(clippy::too_many_arguments)]
    pub fn param(
        self,
        key: impl Into<String>,
        default: Value,
        validator: impl Validator + 'static,
        description: impl Into<String>,
        optional: bool,
    ) -> Self {
        self.param_full(
            key,
            default,
            validator,
            description,
            optional,
            Vec::new(),
            false,
            false,
            "",
            Vec::new(),
            None,
        )
    }

    #[allow(clippy::too_many_arguments)]
    pub fn param_full(
        mut self,
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
        enum_meta: Option<Enum>,
    ) -> Self {
        let key = key.into();
        let def = ParamDef {
            default,
            validator: Arc::new(validator),
            description: description.into(),
            optional,
            injections,
            skip_validation,
            deprecated,
            example: example.into(),
            aliases,
            enum_meta,
        };
        self.options.insert(
            format!("param:{key}"),
            ActionOption::Param {
                key: key.clone(),
                def: def.clone(),
            },
        );
        self.params.insert(key, def);
        self
    }

    pub fn get_params(&self) -> &HashMap<String, ParamDef> {
        &self.params
    }

    pub fn inject(mut self, injection: impl Into<String>) -> Result<Self> {
        let injection = injection.into();
        if self.injections.iter().any(|name| name == &injection) {
            return Err(PlatformError::DuplicateInjection(injection));
        }
        self.options.insert(
            format!("injection:{injection}"),
            ActionOption::Injection {
                name: injection.clone(),
            },
        );
        self.injections.push(injection);
        Ok(self)
    }

    pub fn get_injections(&self) -> &[String] {
        &self.injections
    }

    pub fn get_options(&self) -> impl Iterator<Item = &ActionOption> + '_ {
        self.options.values()
    }

    pub fn set_http_path(mut self, path: impl Into<String>) -> Self {
        self.http_path = Some(path.into());
        self
    }

    pub fn set_http_method(mut self, method: HttpMethod) -> Self {
        self.http_methods = vec![method.as_str().to_string()];
        self
    }

    pub fn set_http_methods<I, S>(mut self, methods: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        let mut methods: Vec<String> = methods.into_iter().map(Into::into).collect();
        methods.sort();
        methods.dedup();
        self.http_methods = methods;
        self
    }

    pub fn http_alias(mut self, path: impl Into<String>) -> Self {
        self.http_aliases.push(path.into());
        self
    }

    pub fn get_http_path(&self) -> Option<&str> {
        self.http_path.as_deref()
    }

    pub fn get_http_methods(&self) -> &[String] {
        &self.http_methods
    }

    pub fn get_http_aliases(&self) -> &[String] {
        &self.http_aliases
    }

    #[cfg(feature = "http")]
    pub(crate) fn resolve_http_callback(&self) -> Result<HttpActionCallback> {
        if let Some(callback) = &self.http_callback {
            return Ok(callback.clone());
        }
        if let Some(sync) = self.sync_callback.clone() {
            return Ok(Arc::new(move |_ctx| {
                let sync = sync.clone();
                Box::pin(async move {
                    (sync)();
                    Ok(())
                })
            }));
        }
        Err(PlatformError::MissingCallback)
    }

    #[cfg(feature = "cli")]
    pub(crate) fn resolve_cli_callback(&self) -> Result<CliActionCallback> {
        if let Some(callback) = &self.cli_callback {
            return Ok(callback.clone());
        }
        if let Some(sync) = self.sync_callback.clone() {
            return Ok(Arc::new(move |_params| {
                (sync)();
                Value::Null
            }));
        }
        Err(PlatformError::MissingCallback)
    }
}
