use crate::key::{FieldSpec, KeySpec};
use std::sync::Arc;
use utopia_validators::{Boolean, Integer, Text, Validator};

/// Built-in validator factory for app-defined key specs.
pub fn builtin_validator(name: &str) -> Arc<dyn Validator> {
    match name {
        "boolean" => Arc::new(Boolean::new()),
        "integer" => Arc::new(Integer::new()),
        _ => Arc::new(Text::new(1024)),
    }
}

/// Helper for building a [`FieldSpec::Key`] with a built-in validator name.
pub fn key_spec(name: impl Into<String>, required: bool, validator: &str) -> FieldSpec {
    FieldSpec::Key(KeySpec {
        name: name.into(),
        required,
        validator: builtin_validator(validator),
        coerce_bool: validator == "boolean",
    })
}
