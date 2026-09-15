use crate::action::{Action, ActionType};
use crate::error::{PlatformError, Result};
use crate::hook_meta::{enum_to_meta, SharedValidator};

/// Adapter for registering platform task actions onto a CLI runtime.
///
/// Implement this trait to integrate `Platform::init_cli` with a custom CLI stack.
/// The default `cli` feature provides [`UtopiaCliRegistrar`] for [`utopia_cli::Cli`].
pub trait CliRegistrar {
    fn register_action(&mut self, action_key: &str, action: &Action) -> Result<()>;
}

/// Built-in registrar that copies platform task actions onto [`utopia_cli::Cli`].
///
/// Mirrors PHP `Platform::initTasks`: init / error / shutdown hooks plus named tasks.
#[derive(Debug)]
pub struct UtopiaCliRegistrar<'a> {
    cli: &'a mut utopia_cli::Cli,
}

impl<'a> UtopiaCliRegistrar<'a> {
    pub fn new(cli: &'a mut utopia_cli::Cli) -> Self {
        Self { cli }
    }
}

impl CliRegistrar for UtopiaCliRegistrar<'_> {
    fn register_action(&mut self, action_key: &str, action: &Action) -> Result<()> {
        let callback = action.resolve_cli_callback()?;

        match action.action_type() {
            ActionType::Init => apply_cli_hook(self.cli.init(), action, callback),
            ActionType::Error => apply_cli_hook(self.cli.error(), action, callback),
            ActionType::Shutdown => apply_cli_hook(self.cli.shutdown(), action, callback),
            ActionType::Default
            | ActionType::Options
            | ActionType::WorkerStart
            | ActionType::WorkerStop => apply_cli_task(self.cli.task(action_key), action, callback),
        }
    }
}

fn apply_cli_hook(
    hook: &mut utopia_cli::CliHook,
    action: &Action,
    callback: utopia_cli::ActionFn,
) -> Result<()> {
    hook.groups(action.get_groups().iter().cloned());
    hook.desc(action.get_desc().unwrap_or(""));
    for (key, param) in action.get_params() {
        hook.param_full(
            key,
            param.default.clone(),
            SharedValidator(param.validator.clone()),
            &param.description,
            param.optional,
            param.injections.clone(),
            param.skip_validation,
            param.deprecated,
            &param.example,
            param.aliases.clone(),
            enum_to_meta(param.enum_meta.as_ref()),
        );
    }
    for injection in action.get_injections() {
        hook.inject(injection)
            .map_err(|e| PlatformError::Other(e.to_string()))?;
    }
    for (key, value) in action.get_labels() {
        hook.label(key, value.clone());
    }
    hook.action(move |params| callback(params));
    Ok(())
}

fn apply_cli_task(
    task: &mut utopia_cli::Task,
    action: &Action,
    callback: utopia_cli::ActionFn,
) -> Result<()> {
    task.groups(action.get_groups().iter().cloned());
    task.desc(action.get_desc().unwrap_or(""));
    for (key, param) in action.get_params() {
        task.param_full(
            key,
            param.default.clone(),
            SharedValidator(param.validator.clone()),
            &param.description,
            param.optional,
            param.injections.clone(),
            param.skip_validation,
            param.deprecated,
            &param.example,
            param.aliases.clone(),
            enum_to_meta(param.enum_meta.as_ref()),
        );
    }
    for injection in action.get_injections() {
        task.inject(injection)
            .map_err(|e| PlatformError::Other(e.to_string()))?;
    }
    for (key, value) in action.get_labels() {
        task.label(key, value.clone());
    }
    task.action(move |params| callback(params));
    Ok(())
}
