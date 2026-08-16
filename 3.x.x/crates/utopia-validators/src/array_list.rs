use crate::{Validator, ValueType};
use serde_json::Value;
use std::fmt::{self, Write};
use std::sync::Arc;

#[derive(Clone)]
pub struct ArrayList {
    element: Arc<dyn Validator>,
    length: Option<usize>,
}

impl fmt::Debug for ArrayList {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ArrayList")
            .field("element", &"Arc<dyn Validator>")
            .field("length", &self.length)
            .finish()
    }
}

impl ArrayList {
    pub fn new(element: impl Validator + 'static) -> Self {
        Self {
            element: Arc::new(element),
            length: None,
        }
    }

    /// PHP's `$length` constructor argument: the *maximum* number of items,
    /// not an exact count.
    pub fn length(mut self, length: usize) -> Self {
        self.length = Some(length);
        self
    }

    /// [`Self::new`] plus [`Self::length`], mirroring PHP's
    /// `new ArrayList($validator, $length)`.
    pub fn with_length(element: impl Validator + 'static, length: usize) -> Self {
        Self::new(element).length(length)
    }
}

impl Validator for ArrayList {
    fn description(&self) -> String {
        let mut message = String::from("Value must a valid array");
        if let Some(length) = self.length.filter(|length| *length > 0) {
            let _ = write!(message, " no longer than {length} items");
        }
        let element = self.element.description();
        if !element.is_empty() && element != "0" {
            let _ = write!(message, " and {element}");
        }
        message
    }

    fn is_array(&self) -> bool {
        true
    }

    /// PHP `ArrayList::getType()` delegates to the element validator; the
    /// `isArray()` flag above is what marks the param itself as a list.
    fn value_type(&self) -> ValueType {
        self.element.value_type()
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(arr) = value.as_array() else {
            return false;
        };
        if !arr.iter().all(|v| self.element.is_valid(v)) {
            return false;
        }
        self.length
            .is_none_or(|length| length == 0 || arr.len() <= length)
    }
}
