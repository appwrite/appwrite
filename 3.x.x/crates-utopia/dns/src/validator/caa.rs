use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

/// PHP `Utopia\DNS\Validator\CAA`.
#[derive(Debug)]
pub struct CAA {
    reason: Mutex<String>,
}

impl Default for CAA {
    fn default() -> Self {
        Self::new()
    }
}

impl CAA {
    pub const CAA_FLAG_MIN: i32 = 0;
    pub const CAA_FLAG_MAX: i32 = 255;
    pub const FAILURE_REASON_INVALID_FLAGS: &'static str =
        "Flags must be a number between 0 and 255";
    pub const FAILURE_REASON_INVALID_TAG: &'static str = "Tag must be a non-empty string";
    pub const FAILURE_REASON_INVALID_VALUE: &'static str =
        "Value must be a non-empty string and must be enclosed in quotes";
    pub const FAILURE_REASON_INVALID_FORMAT: &'static str =
        "CAA record must be in the format <flags> <tag> \"<value>\"";

    #[must_use]
    pub fn new() -> Self {
        Self {
            reason: Mutex::new(String::new()),
        }
    }

    pub fn reason(&self) -> String {
        self.reason.lock().clone()
    }
}

impl Validator for CAA {
    fn description(&self) -> String {
        let reason = self.reason.lock();
        if !reason.is_empty() && *reason != "0" {
            reason.clone()
        } else {
            Self::FAILURE_REASON_INVALID_FORMAT.to_string()
        }
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, data: &Value) -> bool {
        let Some(data) = data.as_str() else {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_FORMAT.into();
            return false;
        };
        let mut parts = data.splitn(3, ' ');
        let (Some(flags), Some(tag), Some(value)) = (parts.next(), parts.next(), parts.next())
        else {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_FORMAT.into();
            return false;
        };
        if !is_numeric(flags) {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_FLAGS.into();
            return false;
        }
        let flags_n: i32 = flags.parse().unwrap_or(i32::MIN);
        if !(Self::CAA_FLAG_MIN..=Self::CAA_FLAG_MAX).contains(&flags_n) {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_FLAGS.into();
            return false;
        }
        if tag.is_empty() {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_TAG.into();
            return false;
        }
        if value.is_empty() || !value.starts_with('"') || !value.ends_with('"') {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_VALUE.into();
            return false;
        }
        let inner = &value[1..value.len() - 1];
        if inner.is_empty() {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_VALUE.into();
            return false;
        }
        true
    }
}

fn is_numeric(s: &str) -> bool {
    !s.is_empty() && s.parse::<f64>().is_ok()
}
