use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::message::Domain;
use crate::message::Record;

/// PHP `Utopia\DNS\Validator\Name`.
#[derive(Debug)]
pub struct Name {
    record_type: Option<u16>,
    reason: Mutex<String>,
}

impl Name {
    pub const LABEL_MAX_LENGTH: usize = 63;
    pub const FAILURE_REASON_INVALID_LABEL_LENGTH: &'static str =
        "Label must be between 1 and 63 characters long";
    pub const FAILURE_REASON_INVALID_NAME_LENGTH: &'static str =
        "Name must be between 1 and 255 characters long";
    pub const FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE: &'static str =
        "Label must contain only alpha-numeric characters and hyphens, and cannot start or end with a hyphen";
    pub const FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE: &'static str =
        "Label must contain only alpha-numeric characters, hyphens and underscores, and cannot start or end with a hyphen";
    pub const FAILURE_REASON_INVALID_WILDCARD: &'static str =
        "Wildcard \"*\" must be the entire leftmost label";
    pub const FAILURE_REASON_GENERAL: &'static str =
        "Name must be between 1 and 255 characters long, and contain only alpha-numeric characters, hyphens and (for non-address record types) underscores, and cannot start or end with a hyphen";

    const RECORD_TYPES_WITH_HOSTNAME_OWNER: [u16; 2] = [Record::TYPE_A, Record::TYPE_AAAA];

    #[must_use]
    pub fn new(record_type: Option<u16>) -> Self {
        Self {
            record_type,
            reason: Mutex::new(String::new()),
        }
    }

    pub fn reason(&self) -> String {
        self.reason.lock().clone()
    }
}

impl Validator for Name {
    fn description(&self) -> String {
        let reason = self.reason.lock();
        if !reason.is_empty() && *reason != "0" {
            reason.clone()
        } else {
            Self::FAILURE_REASON_GENERAL.to_string()
        }
    }

    fn value_type(&self) -> ValueType {
        ValueType::String
    }

    fn is_valid(&self, name: &Value) -> bool {
        let Some(name) = name.as_str() else {
            *self.reason.lock() = Self::FAILURE_REASON_GENERAL.into();
            return false;
        };
        if name.is_empty() || name.len() > Domain::MAX_DOMAIN_NAME_LEN {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_NAME_LENGTH.into();
            return false;
        }
        if name == "@" {
            return true;
        }
        let mut trimmed = name.strip_suffix('.').unwrap_or(name);
        if trimmed == "*" {
            return true;
        }
        if let Some(rest) = trimmed.strip_prefix("*.") {
            trimmed = rest;
        }
        if trimmed.contains('*') {
            *self.reason.lock() = Self::FAILURE_REASON_INVALID_WILDCARD.into();
            return false;
        }
        let underscore_allowed = !self
            .record_type
            .is_some_and(|t| Self::RECORD_TYPES_WITH_HOSTNAME_OWNER.contains(&t));
        for label in trimmed.split('.') {
            if label.is_empty() {
                *self.reason.lock() = if underscore_allowed {
                    Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE
                } else {
                    Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE
                }
                .into();
                return false;
            }
            if label.len() > Self::LABEL_MAX_LENGTH {
                *self.reason.lock() = Self::FAILURE_REASON_INVALID_LABEL_LENGTH.into();
                return false;
            }
            let bytes = label.as_bytes();
            for (i, &ch) in bytes.iter().enumerate() {
                let first_or_last = i == 0 || i == bytes.len() - 1;
                if !is_valid_character(ch, first_or_last, underscore_allowed) {
                    *self.reason.lock() = if underscore_allowed {
                        Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE
                    } else {
                        Self::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE
                    }
                    .into();
                    return false;
                }
            }
        }
        true
    }
}

fn is_valid_character(ch: u8, first_or_last: bool, underscore_allowed: bool) -> bool {
    if first_or_last {
        ch.is_ascii_alphanumeric() || (underscore_allowed && ch == b'_')
    } else {
        ch.is_ascii_alphanumeric() || ch == b'-' || (underscore_allowed && ch == b'_')
    }
}
