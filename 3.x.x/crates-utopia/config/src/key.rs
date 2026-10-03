use std::sync::Arc;
use utopia_validators::Validator;

/// Specification for a configuration key when loading with validation.
#[derive(Clone)]
pub struct KeySpec {
    pub name: String,
    pub required: bool,
    pub validator: Arc<dyn Validator>,
    /// When `true`, dotenv values for this key are coerced to booleans.
    pub coerce_bool: bool,
}

impl KeySpec {
    pub fn new(name: impl Into<String>, validator: impl Validator + 'static) -> Self {
        Self {
            name: name.into(),
            required: false,
            validator: Arc::new(validator),
            coerce_bool: false,
        }
    }

    pub fn required(mut self, required: bool) -> Self {
        self.required = required;
        self
    }

    pub fn coerce_bool(mut self, coerce_bool: bool) -> Self {
        self.coerce_bool = coerce_bool;
        self
    }
}

/// Field specification for structured config loading, including nested groups.
#[derive(Clone, Debug)]
pub enum FieldSpec {
    /// Scalar key resolved from the root map.
    Key(KeySpec),
    /// Nested config loaded from a sub-map at `key`.
    Nested {
        key: String,
        required: bool,
        fields: Vec<FieldSpec>,
    },
}

impl FieldSpec {
    pub fn nested(key: impl Into<String>, required: bool, fields: Vec<FieldSpec>) -> Self {
        Self::Nested {
            key: key.into(),
            required,
            fields,
        }
    }

    pub fn nested_required(key: impl Into<String>, fields: Vec<FieldSpec>) -> Self {
        Self::nested(key, true, fields)
    }
}

impl std::fmt::Debug for KeySpec {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("KeySpec")
            .field("name", &self.name)
            .field("required", &self.required)
            .field("validator", &"Arc<dyn Validator>")
            .field("coerce_bool", &self.coerce_bool)
            .finish()
    }
}
