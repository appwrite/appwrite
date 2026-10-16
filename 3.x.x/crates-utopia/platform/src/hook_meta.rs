use std::sync::Arc;

use utopia_servers::EnumMeta;
#[cfg(feature = "worker")]
use utopia_servers::Hook;
use utopia_validators::Validator;

#[cfg(feature = "worker")]
use crate::action::{Action, ParamDef, SyncCallback};
use crate::enum_type::Enum;
#[cfg(feature = "worker")]
use crate::error::{PlatformError, Result};

#[derive(Clone)]
pub(crate) struct SharedValidator(pub(crate) Arc<dyn Validator>);

impl Validator for SharedValidator {
    fn description(&self) -> String {
        self.0.description()
    }

    fn is_array(&self) -> bool {
        self.0.is_array()
    }

    fn value_type(&self) -> utopia_validators::ValueType {
        self.0.value_type()
    }

    fn is_valid(&self, value: &serde_json::Value) -> bool {
        self.0.is_valid(value)
    }
}

pub(crate) fn enum_to_meta(enum_meta: Option<&Enum>) -> Option<EnumMeta> {
    enum_meta.map(|e| EnumMeta {
        name: e.name.clone(),
        map: e.map.clone(),
        exclude: e.exclude.clone(),
    })
}

#[cfg(feature = "worker")]
pub(crate) fn apply_param_to_hook(hook: &mut Hook, key: &str, param: &ParamDef) {
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

#[cfg(feature = "worker")]
pub(crate) fn apply_action_metadata(hook: &mut Hook, action: &Action) -> Result<()> {
    hook.groups(action.get_groups().iter().cloned());
    if let Some(desc) = action.get_desc() {
        hook.desc(desc);
    }
    for (key, param) in action.get_params() {
        apply_param_to_hook(hook, key, param);
    }
    for injection in action.get_injections() {
        hook.inject(injection)
            .map_err(|e| PlatformError::Other(e.to_string()))?;
    }
    for (key, value) in action.get_labels() {
        hook.label(key, value.clone());
    }
    Ok(())
}

#[cfg(feature = "worker")]
pub(crate) fn resolve_sync_callback(action: &Action) -> Result<SyncCallback> {
    if let Some(callback) = action.get_sync_callback() {
        return Ok(callback.clone());
    }
    Err(PlatformError::MissingCallback)
}
