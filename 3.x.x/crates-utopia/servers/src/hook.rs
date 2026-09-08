use std::collections::HashMap;
use std::sync::Arc;

use serde_json::Value;
use utopia_validators::Validator;

use crate::enum_meta::EnumMeta;
use crate::error::HookError;
use crate::param::ParamDef;

#[derive(Debug, Clone)]
struct Injection {
    name: String,
    order: usize,
}

/// Fluent hook/route builder: params, injections, groups, labels, action metadata.
#[derive(Clone, Debug)]
pub struct Hook {
    desc: String,
    groups: Vec<String>,
    labels: HashMap<String, Value>,
    params: HashMap<String, ParamDef>,
    injections: HashMap<String, Injection>,
    /// Opaque action marker - HTTP crate stores the real async callback separately.
    has_action: bool,
}

impl Default for Hook {
    fn default() -> Self {
        Self::new()
    }
}

impl Hook {
    pub fn new() -> Self {
        Self {
            desc: String::new(),
            groups: Vec::new(),
            labels: HashMap::new(),
            params: HashMap::new(),
            injections: HashMap::new(),
            has_action: false,
        }
    }

    pub fn desc(&mut self, desc: impl Into<String>) -> &mut Self {
        self.desc = desc.into();
        self
    }

    pub fn get_desc(&self) -> &str {
        &self.desc
    }

    pub fn groups<I, S>(&mut self, groups: I) -> &mut Self
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

    pub fn label(&mut self, key: impl Into<String>, value: impl Into<Value>) -> &mut Self {
        self.labels.insert(key.into(), value.into());
        self
    }

    pub fn get_label(&self, key: &str, default: Value) -> Value {
        self.labels.get(key).cloned().unwrap_or(default)
    }

    pub fn action_marker(&mut self) -> &mut Self {
        self.has_action = true;
        self
    }

    pub fn has_action(&self) -> bool {
        self.has_action
    }

    pub fn inject(&mut self, injection: impl Into<String>) -> Result<&mut Self, HookError> {
        let injection = injection.into();
        if self.injections.contains_key(&injection) {
            return Err(HookError::DuplicateInjection(injection));
        }
        let order = self.params.len() + self.injections.len();
        self.injections.insert(
            injection.clone(),
            Injection {
                name: injection,
                order,
            },
        );
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
        let key = key.into();
        let order = self.params.len() + self.injections.len();
        self.params.insert(
            key.clone(),
            ParamDef {
                key,
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
                value: None,
                order,
            },
        );
        self
    }

    pub fn get_params(&self) -> &HashMap<String, ParamDef> {
        &self.params
    }

    pub fn get_params_mut(&mut self) -> &mut HashMap<String, ParamDef> {
        &mut self.params
    }

    pub fn has_injections(&self) -> bool {
        !self.injections.is_empty()
    }

    pub fn get_injections(&self) -> Vec<(String, usize)> {
        let mut items: Vec<_> = self
            .injections
            .values()
            .map(|i| (i.name.clone(), i.order))
            .collect();
        items.sort_by_key(|(_, o)| *o);
        items
    }

    pub fn get_dependencies(&self) -> Vec<String> {
        self.get_injections()
            .into_iter()
            .map(|(name, _)| name)
            .collect()
    }

    pub fn set_param_value(&mut self, key: &str, value: Value) -> Result<(), HookError> {
        let param = self.params.get_mut(key).ok_or(HookError::UnknownKey)?;
        param.value = Some(value);
        Ok(())
    }

    pub fn get_param_value(&self, key: &str) -> Result<Option<&Value>, HookError> {
        let param = self.params.get(key).ok_or(HookError::UnknownKey)?;
        Ok(param.value.as_ref())
    }

    /// Ordered list of param keys + injection names by `order`.
    pub fn argument_order(&self) -> Vec<(ArgumentKind, String, usize)> {
        let mut items = Vec::new();
        for p in self.params.values() {
            items.push((ArgumentKind::Param, p.key.clone(), p.order));
        }
        for i in self.injections.values() {
            items.push((ArgumentKind::Injection, i.name.clone(), i.order));
        }
        items.sort_by_key(|(_, _, o)| *o);
        items
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ArgumentKind {
    Param,
    Injection,
}
