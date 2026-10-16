use std::sync::Arc;

use parking_lot::Mutex;
use serde_json::Value;
use utopia_servers::{EnumMeta, Hook, HookError};
use utopia_validators::Validator;

use crate::action::ActionArgs;
use crate::error::QueueError;

/// PHP `Utopia\Queue\Job` - Hook metadata plus a stored action callback.
#[derive(Clone)]
pub struct Job {
    hook: Hook,
    use_hook: bool,
    action: Arc<Mutex<Option<ActionFn>>>,
}

pub type ActionFn = Arc<dyn Fn(&ActionArgs) -> Result<(), QueueError> + Send + Sync>;

impl std::fmt::Debug for Job {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Job")
            .field("use_hook", &self.use_hook)
            .field("hook", &self.hook)
            .finish_non_exhaustive()
    }
}

impl Default for Job {
    fn default() -> Self {
        Self::new()
    }
}

impl Job {
    pub fn new() -> Self {
        Self {
            hook: Hook::new(),
            use_hook: true,
            action: Arc::new(Mutex::new(None)),
        }
    }

    /// PHP `hook(bool $hook = true)`.
    pub fn hook(&mut self, enabled: bool) -> &mut Self {
        self.use_hook = enabled;
        self
    }

    pub fn get_hook(&self) -> bool {
        self.use_hook
    }

    pub fn desc(&mut self, desc: impl Into<String>) -> &mut Self {
        self.hook.desc(desc);
        self
    }

    pub fn groups<I, S>(&mut self, groups: I) -> &mut Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        self.hook.groups(groups);
        self
    }

    pub fn get_groups(&self) -> Vec<String> {
        self.hook.get_groups().to_vec()
    }

    pub fn label(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self {
        self.hook.label(key, value);
        self
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
        enum_meta: Option<EnumMeta>,
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

    pub fn action<F>(&mut self, f: F) -> &mut Self
    where
        F: Fn(&ActionArgs) -> Result<(), QueueError> + Send + Sync + 'static,
    {
        *self.action.lock() = Some(Arc::new(f));
        self.hook.action_marker();
        self
    }

    pub fn get_action(&self) -> Option<ActionFn> {
        self.action.lock().clone()
    }

    pub fn hook_meta(&self) -> &Hook {
        &self.hook
    }

    pub fn hook_meta_mut(&mut self) -> &mut Hook {
        &mut self.hook
    }
}
