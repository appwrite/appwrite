//! PHP `Utopia\Database\Validator\Permissions`.

use parking_lot::Mutex;
use serde_json::Value;
use utopia_validators::{Validator, ValueType};

use crate::constants::{PERMISSIONS, PERMISSION_WRITE};
use crate::helpers::Permission;
use crate::validator::Roles;

/// PHP `Utopia\Database\Validator\Permissions`.
#[derive(Debug)]
pub struct Permissions {
    roles: Roles,
    allowed: Vec<String>,
    length: i64,
    message: Mutex<String>,
}

impl Clone for Permissions {
    fn clone(&self) -> Self {
        Self {
            roles: self.roles.clone(),
            allowed: self.allowed.clone(),
            length: self.length,
            message: Mutex::new(self.message.lock().clone()),
        }
    }
}

impl Permissions {
    #[must_use]
    pub fn new(length: i64, allowed: &[&str]) -> Self {
        Self {
            roles: Roles::default(),
            allowed: allowed.iter().map(|s| (*s).to_owned()).collect(),
            length,
            message: Mutex::new("Permissions Error".into()),
        }
    }

    fn set_message(&self, message: impl Into<String>) {
        *self.message.lock() = message.into();
    }
}

impl Default for Permissions {
    fn default() -> Self {
        let mut allowed: Vec<&str> = PERMISSIONS.to_vec();
        allowed.push(PERMISSION_WRITE);
        Self::new(0, &allowed)
    }
}

impl Validator for Permissions {
    fn description(&self) -> String {
        self.message.lock().clone()
    }

    fn value_type(&self) -> ValueType {
        ValueType::Array
    }

    fn is_valid(&self, value: &Value) -> bool {
        let Some(arr) = value.as_array() else {
            self.set_message("Permissions must be an array of strings.");
            return false;
        };
        if self.length > 0 && arr.len() as i64 > self.length {
            self.set_message(format!(
                "You can only provide up to {} permissions.",
                self.length
            ));
            return false;
        }
        for permission in arr {
            let Some(s) = permission.as_str() else {
                self.set_message("Every permission must be of type string.");
                return false;
            };
            if s == "*" {
                self.set_message(
                    "Wildcard permission \"*\" has been replaced. Use \"any\" instead.",
                );
                return false;
            }
            if s.contains("role:") {
                self.set_message("Permissions using the \"role:\" prefix have been replaced. Use \"users\", \"guests\", or \"any\" instead.");
                return false;
            }
            let is_allowed = self.allowed.iter().any(|allowed| s.starts_with(allowed));
            if !is_allowed {
                self.set_message(format!(
                    "Permission \"{s}\" is not allowed. Must be one of: {}.",
                    self.allowed.join(", ")
                ));
                return false;
            }
            let parsed = match Permission::parse(s) {
                Ok(p) => p,
                Err(e) => {
                    self.set_message(e.message().to_owned());
                    return false;
                }
            };
            if !self.roles.is_valid_role(
                parsed.get_role(),
                parsed.get_identifier(),
                parsed.get_dimension(),
            ) {
                self.set_message(self.roles.description());
                return false;
            }
        }
        true
    }
}
