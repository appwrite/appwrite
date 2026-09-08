use serde_json::Value;
use std::sync::Arc;
use utopia_validators::Validator;

use crate::enum_meta::EnumMeta;

/// Parameter metadata attached to a Hook/Route.
#[derive(Clone)]
pub struct ParamDef {
    pub key: String,
    pub default: Value,
    pub validator: Arc<dyn Validator>,
    pub description: String,
    pub optional: bool,
    pub injections: Vec<String>,
    pub skip_validation: bool,
    pub deprecated: bool,
    pub example: String,
    pub aliases: Vec<String>,
    pub enum_meta: Option<EnumMeta>,
    pub value: Option<Value>,
    pub order: usize,
}

impl std::fmt::Debug for ParamDef {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("ParamDef")
            .field("key", &self.key)
            .field("default", &self.default)
            .field("validator", &"Arc<dyn Validator>")
            .field("description", &self.description)
            .field("optional", &self.optional)
            .field("injections", &self.injections)
            .field("skip_validation", &self.skip_validation)
            .field("deprecated", &self.deprecated)
            .field("example", &self.example)
            .field("aliases", &self.aliases)
            .field("enum_meta", &self.enum_meta)
            .field("value", &self.value)
            .field("order", &self.order)
            .finish()
    }
}
