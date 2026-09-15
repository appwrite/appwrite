use crate::{Validator, ValueType};
use serde_json::Value;

#[derive(Debug, Clone, Default)]
pub struct Json;

impl Validator for Json {
    fn description(&self) -> String {
        "Value must be a valid JSON string".into()
    }

    fn value_type(&self) -> ValueType {
        Self::TYPE
    }

    fn is_valid(&self, value: &Value) -> bool {
        match value {
            Value::String(s) => serde_json::from_str::<Value>(s).is_ok(),
            Value::Object(_) | Value::Array(_) => true,
            _ => false,
        }
    }
}

impl Json {
    const TYPE: ValueType = ValueType::String;
}

/// Validate JSON array length / element validator.
pub mod array {
    use super::*;
    use std::fmt;
    use std::sync::Arc;

    pub struct ArrayValidator {
        min: usize,
        max: Option<usize>,
        element: Option<Arc<dyn Validator>>,
    }

    impl fmt::Debug for ArrayValidator {
        fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
            f.debug_struct("ArrayValidator")
                .field("min", &self.min)
                .field("max", &self.max)
                .field(
                    "element",
                    &self.element.as_ref().map(|_| "Arc<dyn Validator>"),
                )
                .finish()
        }
    }

    impl ArrayValidator {
        pub fn new() -> Self {
            Self {
                min: 0,
                max: None,
                element: None,
            }
        }

        pub fn min(mut self, min: usize) -> Self {
            self.min = min;
            self
        }

        pub fn max(mut self, max: usize) -> Self {
            self.max = Some(max);
            self
        }

        pub fn element(mut self, v: impl Validator + 'static) -> Self {
            self.element = Some(Arc::new(v));
            self
        }
    }

    impl Default for ArrayValidator {
        fn default() -> Self {
            Self::new()
        }
    }

    impl Validator for ArrayValidator {
        fn description(&self) -> String {
            "Value must be a valid JSON array".into()
        }

        fn is_array(&self) -> bool {
            true
        }

        fn value_type(&self) -> ValueType {
            ValueType::Array
        }

        fn is_valid(&self, value: &Value) -> bool {
            let Some(arr) = value.as_array() else {
                return false;
            };
            if arr.len() < self.min {
                return false;
            }
            if let Some(max) = self.max {
                if arr.len() > max {
                    return false;
                }
            }
            if let Some(el) = &self.element {
                arr.iter().all(|v| el.is_valid(v))
            } else {
                true
            }
        }
    }
}

pub mod object {
    use super::*;

    #[derive(Debug)]
    pub struct ObjectValidator {
        required: Vec<String>,
    }

    impl ObjectValidator {
        pub fn new() -> Self {
            Self {
                required: Vec::new(),
            }
        }

        pub fn required(mut self, keys: impl IntoIterator<Item = impl Into<String>>) -> Self {
            self.required = keys.into_iter().map(Into::into).collect();
            self
        }
    }

    impl Default for ObjectValidator {
        fn default() -> Self {
            Self::new()
        }
    }

    impl Validator for ObjectValidator {
        fn description(&self) -> String {
            "Value must be a valid JSON object".into()
        }

        fn value_type(&self) -> ValueType {
            ValueType::Object
        }

        fn is_valid(&self, value: &Value) -> bool {
            let Some(obj) = value.as_object() else {
                return false;
            };
            self.required.iter().all(|k| obj.contains_key(k))
        }
    }
}
